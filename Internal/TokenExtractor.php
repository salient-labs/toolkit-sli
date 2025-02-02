<?php declare(strict_types=1);

namespace Salient\Sli\Internal;

use Salient\Utility\Exception\ShouldNotHappenException;
use Salient\Utility\File;
use Salient\Utility\Get;
use Salient\Utility\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;

/**
 * @internal
 */
final class TokenExtractor
{
    /** @var NavigableToken[] */
    private array $Tokens;
    private ?string $Filename;
    private ?self $Parent = null;
    private ?NavigableToken $ParentToken = null;
    private ?string $Namespace = null;
    private ?string $Class = null;
    private ?NavigableToken $ClassToken = null;
    private ?string $Member = null;
    private ?NavigableToken $MemberToken = null;
    /** @var array<string,array{\T_CLASS|\T_FUNCTION|\T_CONST,string}> */
    private array $Imports;

    /**
     * Creates a new TokenExtractor object from PHP code
     */
    public function __construct(string $code, ?string $filename = null)
    {
        $this->Tokens = NavigableToken::tokenize($code, \TOKEN_PARSE, true);
        $this->Filename = $filename;
    }

    /**
     * Creates a new TokenExtractor object from a file, optionally applying an
     * end-of-line sequence to its contents before they are tokenized
     */
    public static function fromFile(string $filename, ?string $eol = null): self
    {
        $code = File::getContents($filename);
        if ($eol !== null) {
            $code = Str::setEol($code, $eol);
        }
        return new self($code, $filename);
    }

    /**
     * Creates a new TokenExtractor object for a class from the file in which it
     * is declared
     *
     * @param ReflectionClass<*>|class-string $class
     */
    public static function forClass($class, ?string $eol = null): self
    {
        if (!$class instanceof ReflectionClass) {
            $class = new ReflectionClass($class);
        }

        $file = $class->getFileName();
        if ($file === false) {
            // @codeCoverageIgnoreStart
            throw new LogicException(sprintf(
                '%s not loaded from file',
                $class->getName(),
            ));
            // @codeCoverageIgnoreEnd
        }

        $name = $class->getShortName();
        $namespace = $class->getNamespaceName();
        foreach (self::fromFile($file, $eol)->getNamespaces() as $ns => $extractor) {
            if (strcasecmp($ns, $namespace)) {
                continue;
            }
            foreach ($extractor->getClasses() as $className => $extractor) {
                if (!strcasecmp($className, $name)) {
                    return $extractor;
                }
            }
        }

        // @codeCoverageIgnoreStart
        throw new ShouldNotHappenException(sprintf(
            '%s not found in %s',
            $class->getName(),
            $file,
        ));
        // @codeCoverageIgnoreEnd
    }

    /**
     * Iterate over tokens in the extractor, optionally filtering them by ID
     *
     * @return iterable<int,NavigableToken>
     */
    public function getTokens(int ...$id): iterable
    {
        if ($id) {
            $idx = array_fill_keys($id, true);
            foreach ($this->Tokens as $index => $token) {
                if ($idx[$token->id] ?? false) {
                    yield $index => $token;
                }
            }
        } else {
            yield from $this->Tokens;
        }
    }

    /**
     * Get the filename of the extractor if one was passed to the constructor
     */
    public function getFilename(): ?string
    {
        return $this->Filename;
    }

    /**
     * Get the parent of the extractor
     */
    public function getParent(): ?self
    {
        return $this->Parent;
    }

    /**
     * Check if the extractor represents a namespace
     *
     * @phpstan-assert-if-true !null $this->getNamespace()
     * @phpstan-assert-if-true !null $this->getParent()
     */
    public function hasNamespace(): bool
    {
        return $this->Namespace !== null;
    }

    /**
     * Get the namespace of the extractor
     *
     * Returns `null` if the extractor does not have a namespace applied by
     * {@see TokenExtractor::getNamespaces()}.
     */
    public function getNamespace(): ?string
    {
        return $this->Namespace;
    }

    /**
     * Iterate over namespaces in the extractor
     *
     * @return iterable<string,static>
     */
    public function getNamespaces(): iterable
    {
        if (!$this->Tokens || $this->Namespace !== null) {
            return;
        }

        $start = reset($this->Tokens);
        $namespace = '';
        foreach ($this->getTokens(\T_NAMESPACE) as $token) {
            if ($start && $token->Prev && $start !== $token) {
                $end = $token->Prev;
                yield $namespace =>
                    $this->getRange($start, $end)->applyNamespace($namespace);
            }

            /** @var NavigableToken */
            $token = $token->getNextCodeOrCloseTag();
            $namespace = $this->doGetName($token);
            switch ($token->id) {
                case \T_OPEN_BRACE:
                    yield $namespace =>
                        $this->getBlock($token)->applyNamespace($namespace);
                    /** @var NavigableToken */
                    $token = $token->ClosedBy;
                    $start = $token->Next;
                    $namespace = '';
                    break;

                case \T_SEMICOLON:
                case \T_CLOSE_TAG:
                    $start = $token->Next;
                    break;

                default:
                    // @codeCoverageIgnoreStart
                    throw new ShouldNotHappenException(sprintf(
                        'Unexpected %s after namespace',
                        $token->getTokenName(),
                    ));
                    // @codeCoverageIgnoreEnd
            }
        }

        if ($start) {
            $end = end($this->Tokens);
            yield $namespace =>
                $this->getRange($start, $end)->applyNamespace($namespace);
        }
    }

    private function applyNamespace(string $namespace): self
    {
        $this->Namespace = $namespace;
        return $this;
    }

    /**
     * Check if the extractor represents a class
     *
     * @phpstan-assert-if-true !null $this->getClass()
     * @phpstan-assert-if-true !null $this->getClassToken()
     * @phpstan-assert-if-true !null $this->getParent()
     */
    public function hasClass(): bool
    {
        return $this->Class !== null;
    }

    /**
     * Get the extractor's class
     *
     * Returns `null` if the extractor does not have a class applied by
     * {@see TokenExtractor::getClasses()}.
     */
    public function getClass(): ?string
    {
        return $this->Class;
    }

    /**
     * Get the T_CLASS, T_INTERFACE, T_TRAIT or T_ENUM token associated with the
     * extractor's class
     *
     * Returns `null` if the extractor does not have a class applied by
     * {@see TokenExtractor::getClasses()}.
     */
    public function getClassToken(): ?NavigableToken
    {
        return $this->ClassToken;
    }

    /**
     * Iterate over classes, interfaces, traits and enums in the extractor
     *
     * @return iterable<string,static>
     */
    public function getClasses(): iterable
    {
        if (!$this->Tokens || $this->Class !== null) {
            return;
        }

        foreach ($this->getTokens(\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM) as $token) {
            $next = $token->NextCode;
            if ($next && $next->id === \T_STRING) {
                $class = $next->text;
                if ($next = $next->getNextSibling(\T_OPEN_BRACE)) {
                    yield $class => $this->getBlock($next)->applyClass($class, $token);
                    continue;
                }
                // @codeCoverageIgnoreStart
                throw new ShouldNotHappenException(sprintf('No block for %s', $class));
                // @codeCoverageIgnoreEnd
            }
        }
    }

    private function applyClass(string $class, NavigableToken $token): self
    {
        $this->Class = $class;
        $this->ClassToken = $token;
        return $this;
    }

    /**
     * Check if the extractor represents a function, property or constant
     *
     * @phpstan-assert-if-true !null $this->getMember()
     * @phpstan-assert-if-true !null $this->getMemberToken()
     * @phpstan-assert-if-true !null $this->getParent()
     */
    public function hasMember(): bool
    {
        return $this->Member !== null;
    }

    /**
     * Get the name of the extractor's function, property or constant
     *
     * Returns `null` if the extractor does not have a member applied by:
     *
     * - {@see TokenExtractor::getFunctions()}
     * - {@see TokenExtractor::getProperties()}, or
     * - {@see TokenExtractor::getConstants()}
     */
    public function getMember(): ?string
    {
        return $this->Member;
    }

    /**
     * Get the token associated with the extractor's function, property or
     * constant
     *
     * Returns `null` if the extractor does not have a member applied by:
     *
     * - {@see TokenExtractor::getFunctions()}
     * - {@see TokenExtractor::getProperties()}, or
     * - {@see TokenExtractor::getConstants()}
     */
    public function getMemberToken(): ?NavigableToken
    {
        return $this->MemberToken;
    }

    /**
     * Iterate over functions in the extractor
     *
     * @return iterable<string,static>
     */
    public function getFunctions(): iterable
    {
        foreach ($this->getTokens(\T_FUNCTION) as $token) {
            // Exclude `use function ...`
            if ($token->PrevCode && $token->PrevCode->id === \T_USE) {
                continue;
            }
            $next = $token->NextCode;
            if ($next && $next->text === '&') {
                $next = $next->NextCode;
            }
            // Exclude anonymous functions
            if ($next && $next->id === \T_STRING) {
                $function = $next->text;
                if ($next = $next->getNextSibling(\T_OPEN_BRACE, \T_SEMICOLON, \T_CLOSE_TAG)) {
                    if ($next->id === \T_OPEN_BRACE) {
                        yield $function => $this->getBlock($next)->applyMember($function, $token);
                    } else {
                        yield $function => $this->getChild()->applyMember($function, $token);
                    }
                    continue;
                }
                // @codeCoverageIgnoreStart
                throw new ShouldNotHappenException(sprintf('No block for %s()', $function));
                // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * Iterate over properties in the extractor
     *
     * @return iterable<string,static>
     */
    public function getProperties(): iterable
    {
        if (
            !$this->ParentToken
            || !$this->ClassToken
            || $this->ClassToken->getNextSibling(\T_OPEN_BRACE) !== $this->ParentToken
        ) {
            throw new LogicException('Extractor does not represent a class');
        }

        foreach ($this->getTokens(\T_VARIABLE) as $token) {
            if ($token->Parent !== $this->ParentToken) {
                continue;
            }
            $property = substr($token->text, 1);
            if (
                ($next = $token->NextCode)
                && $next->id === \T_EQUAL
                && ($from = $next->NextCode)
                && ($next = $from->getNextSibling(\T_COMMA, \T_SEMICOLON, \T_CLOSE_TAG))
                && ($to = $next->PrevCode)
            ) {
                yield $property => $this->getRange($from, $to)->applyMember($property, $token);
            } else {
                yield $property => $this->getChild()->applyMember($property, $token);
            }
        }
    }

    /**
     * Iterate over constants in the extractor
     *
     * @return iterable<string,static>
     */
    public function getConstants(): iterable
    {
        foreach ($this->getTokens(\T_CONST) as $token) {
            // Exclude `use const ...`
            if ($token->PrevCode && $token->PrevCode->id === \T_USE) {
                continue;
            }
            if (
                ($next = $token->getNextSibling(\T_EQUAL, \T_COMMA, \T_SEMICOLON, \T_CLOSE_TAG))
                && $next->id === \T_EQUAL
                && $next->PrevCode
            ) {
                $constant = $next->PrevCode->text;
                $from = $next->NextCode;
                if (
                    $from
                    && ($next = $from->getNextSibling(\T_COMMA, \T_SEMICOLON, \T_CLOSE_TAG))
                    && ($to = $next->PrevCode)
                ) {
                    yield $constant => $this->getRange($from, $to)->applyMember($constant, $token);
                    continue;
                }
            }
            // @codeCoverageIgnoreStart
            throw new ShouldNotHappenException('No value for constant');
            // @codeCoverageIgnoreEnd
        }
    }

    private function applyMember(string $member, NavigableToken $token): self
    {
        $this->Member = $member;
        $this->MemberToken = $token;
        return $this;
    }

    /**
     * Get a fully-qualified name from a token in the extractor, optionally
     * assigning the next code token to a variable
     *
     * @param-out NavigableToken $next
     * @return class-string|null
     */
    public function getName(NavigableToken $token, ?NavigableToken &$next = null): ?string
    {
        $this->assertHasNamespace();
        $this->assertHasToken($token);

        $name = $this->doGetName($token);
        $next = $token;

        if ($name === '') {
            return null;
        }

        if (Str::startsWith($name, 'namespace\\', true)) {
            $name = $this->Namespace . substr($name, 9);
        } elseif (strpos($name, '\\') === false) {
            $this->Imports ??= array_change_key_case(Get::array(
                $this->getImports(),
            ));
            $name = $this->Imports[Str::lower($name)][1]
                ?? $this->Namespace . '\\' . $name;
        }

        /** @var class-string */
        return ltrim($name, '\\');
    }

    /**
     * @param-out NavigableToken $token
     */
    private function doGetName(NavigableToken &$token): string
    {
        $name = $token->getName($next);
        if (!$next) {
            // @codeCoverageIgnoreStart
            throw new ShouldNotHappenException('No token after name');
            // @codeCoverageIgnoreEnd
        }
        $token = $next;
        return $name;
    }

    /**
     * Iterate over imports in the extractor
     *
     * @return iterable<string,array{\T_CLASS|\T_FUNCTION|\T_CONST,string}> An
     * iterator that maps aliases to import type and name.
     */
    public function getImports(): iterable
    {
        foreach ($this->getTokens(\T_USE) as $token) {
            // Exclude `function () use (<variable>) {}`
            if ($token->PrevCode && $token->PrevCode->id === \T_CLOSE_PARENTHESIS) {
                continue;
            }
            // Exclude `class <name> { use <trait>; }`
            if ($token->Parent && (
                $token->Parent->id !== \T_OPEN_BRACE
                || !$token->Parent->PrevCode
                || !$token->Parent->PrevCode->isDeclarationOf(\T_NAMESPACE)
            )) {
                continue;
            }
            // Detect `use function` and `use const`
            if ($token->NextCode && (
                $token->NextCode->id === \T_FUNCTION
                || $token->NextCode->id === \T_CONST
            )) {
                $type = $token->NextCode->id;
                $token = $token->NextCode;
            } else {
                $type = \T_CLASS;
            }
            if ($token = $token->NextCode) {
                $imports = $this->doGetImports($token);
                foreach ($imports as $alias => $import) {
                    yield $alias => [$type, ltrim($import, '\\')];
                }
            }
        }
    }

    /**
     * @return iterable<string,string>
     */
    private function doGetImports(NavigableToken $token, string $prefix = ''): iterable
    {
        $current = $prefix;
        $saved = false;
        do {
            $current .= $this->doGetName($token);
            switch ($token->id) {
                case \T_AS:
                    /** @var NavigableToken */
                    $token = $token->NextCode;
                    if ($token->id !== \T_STRING) {
                        // @codeCoverageIgnoreStart
                        throw new ShouldNotHappenException('No T_STRING after T_AS');
                        // @codeCoverageIgnoreEnd
                    }
                    yield $token->text => $current;
                    $saved = true;
                    break;

                case \T_COMMA:
                    if (!$saved) {
                        yield Get::basename($current) => $current;
                    }
                    $current = $prefix;
                    $saved = false;
                    break;

                case \T_OPEN_BRACE:
                    /** @var NavigableToken */
                    $next = $token->NextCode;
                    yield from $this->doGetImports($next, $current);
                    $saved = true;
                    /** @var NavigableToken */
                    $token = $token->ClosedBy;
                    break;

                case \T_CLOSE_BRACE:
                case \T_SEMICOLON:
                case \T_CLOSE_TAG:
                    if (!$saved) {
                        yield Get::basename($current) => $current;
                    }
                    return;
            }
        } while ($token = $token->Next);
    }

    private function getBlock(NavigableToken $bracket): self
    {
        return $this->getChild($bracket->getInnerTokens(), $bracket);
    }

    private function getRange(NavigableToken $from, NavigableToken $to): self
    {
        return $this->getChild($from->getTokens($to));
    }

    /**
     * @param NavigableToken[] $tokens
     */
    private function getChild(array $tokens = [], ?NavigableToken $parentToken = null): self
    {
        $child = clone $this;
        $child->Tokens = $tokens;
        $child->Parent = $this;
        $child->ParentToken = $parentToken;
        return $child;
    }

    /**
     * @phpstan-assert !null $this->Namespace
     */
    private function assertHasNamespace(): void
    {
        if ($this->Namespace === null) {
            // @codeCoverageIgnoreStart
            throw new LogicException('Extractor has no namespace');
            // @codeCoverageIgnoreEnd
        }
    }

    private function assertHasToken(NavigableToken $token): void
    {
        if (($this->Tokens[$token->Index] ?? null) !== $token) {
            // @codeCoverageIgnoreStart
            throw new InvalidArgumentException('$token does not belong to extractor');
            // @codeCoverageIgnoreEnd
        }
    }
}
