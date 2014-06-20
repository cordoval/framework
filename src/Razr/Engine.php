<?php

namespace Pagekit\Razr;

use Pagekit\Razr\Directive\DirectiveInterface;
use Pagekit\Razr\Exception\InvalidArgumentException;
use Pagekit\Razr\Exception\LogicException;
use Pagekit\Razr\Exception\RuntimeException;
use Pagekit\Razr\Extension\CoreExtension;
use Pagekit\Razr\Extension\ExtensionInterface;
use Pagekit\Razr\Storage\Storage;
use Pagekit\Razr\Storage\FileStorage;
use Pagekit\Razr\Storage\StringStorage;

class Engine
{
    const ANY_CALL    = 'any';
    const ARRAY_CALL  = 'array';
    const METHOD_CALL = 'method';

    protected $lexer;
    protected $parser;
    protected $current;
    protected $charset = 'UTF-8';
    protected $parents = array();
    protected $globals = array();
    protected $directives = array();
    protected $functions = array();
    protected $extensions = array();
    protected $cache = array();
    protected $cachePath;

    private $initialized;
    private $template;
    private $parameters;
    private static $classes = array();

    /**
     * Constructor.
     *
     * @param string $cachePath
     */
    public function __construct($cachePath = null)
    {
        $this->lexer = new Lexer($this);
        $this->parser = new Parser($this);
        $this->addExtension(new CoreExtension);

        if (file_exists($cachePath)) {
            $this->cachePath = $cachePath;
        }
    }

    /**
     * Gets the lexer.
     *
     * @return Lexer
     */
    public function getLexer()
    {
        return $this->lexer;
    }

    /**
     * Gets the parser.
     *
     * @return Parser
     */
    public function getParser()
    {
        return $this->parser;
    }

    /**
     * Gets the charset.
     *
     * @return string
     */
    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * Sets the charset.
     *
     * @param string $charset
     */
    public function setCharset($charset)
    {
        $this->charset = $charset;
    }

    /**
     * Gets all global parameters.
     *
     * @return array
     */
    public function getGlobals()
    {
        return $this->globals;
    }

    /**
     * Adds a global parameter.
     *
     * @param string $name
     * @param mixed  $value
     */
    public function addGlobal($name, $value)
    {
        $this->globals[$name] = $value;
    }

    /**
     * Gets a directives.
     *
     * @param  string   $name
     * @return Directive
     */
    public function getDirective($name)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return isset($this->directives[$name]) ? $this->directives[$name] : null;
    }

    /**
     * Gets the directives.
     *
     * @return array
     */
    public function getDirectives()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->directives;
    }

    /**
     * Adds a directive.
     *
     * @param DirectiveInterface $directive
     */
    public function addDirective(DirectiveInterface $directive)
    {
        if ($this->initialized) {
            throw new LogicException(sprintf('Unable to add directive "%s" as they have already been initialized.', $directive->getName()));
        }

        $directive->setEngine($this);

        $this->directives[$directive->getName()] = $directive;
    }

    /**
     * Gets a function.
     *
     * @param  string   $name
     * @return callable
     */
    public function getFunction($name)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return isset($this->functions[$name]) ? $this->functions[$name] : null;
    }

    /**
     * Gets the functions.
     *
     * @return array
     */
    public function getFunctions()
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->functions;
    }

    /**
     * Adds a function.
     *
     * @param string   $name
     * @param callable $function
     */
    public function addFunction($name, $function)
    {
        if ($this->initialized) {
            throw new LogicException(sprintf('Unable to add function "%s" as they have already been initialized.', $directive->getName()));
        }

        $this->functions[$name] = $function;
    }

    /**
     * Gets an extension.
     *
     * @param  string   $name
     * @return Extension
     */
    public function getExtension($name)
    {
        return isset($this->extensions[$name]) ? $this->extensions[$name] : null;
    }

    /**
     * Gets the extensions.
     *
     * @return array
     */
    public function getExtensions()
    {
        return $this->extensions;
    }

    /**
     * Adds an extension.
     *
     * @param ExtensionInterface $extension
     */
    public function addExtension(ExtensionInterface $extension)
    {
        if ($this->initialized) {
            throw new LogicException(sprintf('Unable to add extension "%s" as they have already been initialized.', $extension->getName()));
        }

        $this->extensions[$extension->getName()] = $extension;
    }

    /**
     * Gets an attribute value from an array or object.
     *
     * @param  mixed  $object
     * @param  mixed  $name
     * @param  array  $args
     * @param  string $type
     * @return mixed
     */
    public function getAttribute($object, $name, array $args = array(), $type = self::ANY_CALL)
    {
        // array
        if ($type == self::ANY_CALL || $type == self::ARRAY_CALL) {

            $key = is_bool($name) || is_float($name) ? (int) $name : $name;

            if ((is_array($object) && array_key_exists($key, $object)) || ($object instanceof \ArrayAccess && isset($object[$key]))) {
                return $object[$key];
            }

            if ($type == self::ARRAY_CALL) {
                return null;
            }
        }

        // object
        if (!is_object($object)) {
            return null;
        }

        $call = false;

        if ($type == self::ANY_CALL) {

            // property
            if (isset($object->$name)) {
                return $object->$name;
            }

            // method
            $name  = (string) $name;
            $item  = strtolower($name);
            $class = get_class($object);

            if (!isset(self::$classes[$class])) {
                self::$classes[$class] = array_change_key_case(array_flip(get_class_methods($object)));
            }

            if (!isset(self::$classes[$class][$item])) {
                if (isset(self::$classes[$class]["get$item"])) {
                    $name = "get$name";
                } elseif (isset(self::$classes[$class]["is$item"])) {
                    $name = "is$name";
                } elseif (isset(self::$classes[$class]["__call"])) {
                    $call = true;
                } else {
                    return null;
                }
            }
        }

        try {
            return call_user_func_array(array($object, $name), $args);
        } catch (\BadMethodCallException $e) {
            if (!$call) throw $e;
        }
    }

    /**
     * Calls a function with an array of arguments.
     *
     * @param  string $name
     * @param  array  $args
     * @return mixed
     */
    public function callFunction($name, array $args = array())
    {
        return call_user_func_array($this->getFunction($name), $args);
    }

    /**
     * Decorates a template with another template.
     *
     * @param string $template
     */
    public function extend($template)
    {
        $this->parents[$this->current] = $template;
    }

    /**
     * Escapes a html entities in a string.
     *
     * @param  mixed $value
     * @return string
     */
    public function escape($value)
    {
        if (is_numeric($value)) {
            return $value;
        }

        return is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, $this->charset, false) : $value;
    }

    /**
     * Renders a template.
     *
     * @return string
     */
    public function render($name, array $parameters = array())
    {
        $storage = $this->load($name);
        $parameters = array_replace($this->getGlobals(), $parameters);

        $this->current = $key = sha1(serialize($storage));
        $this->parents[$key] = null;

        if (false === $content = $this->evaluate($storage, $parameters)) {
            throw new RuntimeException('The template cannot be rendered.');
        }

        if ($this->parents[$key]) {
            $content = $this->render($this->parents[$key], $parameters);
        }

        return $content;
    }

    /**
     * Evaluates a template.
     *
     * @param  Storage $template
     * @param  array   $parameters
     * @return string|false
     */
    protected function evaluate(Storage $template, array $parameters = array())
    {
        $this->template = $template;
        $this->parameters = $parameters;
        unset($template, $parameters);

        extract($this->parameters, EXTR_SKIP);
        $this->parameters = null;

        if ($this->template instanceof FileStorage) {

            ob_start();
            require $this->template;
            $this->template = null;

            return ob_get_clean();

        } elseif ($this->template instanceof StringStorage) {

            ob_start();
            eval('; ?>'.$this->template.'<?php ;');
            $this->template = null;

            return ob_get_clean();
        }

        return false;
    }

    /**
     * Compiles a template.
     *
     * @param  string $source
     * @param  string $filename
     * @return string
     */
    protected function compile($source, $filename = null)
    {
        $tokens = $this->lexer->tokenize($source, $filename);
        $source = $this->parser->parse($tokens);

        return $source;
    }

    /**
     * Loads a template.
     *
     * @param  string $name
     * @return Storage
     */
    protected function load($name)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        if (!file_exists($name)) {
            throw new InvalidArgumentException(sprintf('The template "%s" does not exist.', $name));
        }

        $cache = $this->cachePath ? $this->cachePath.'/'.sha1($name) : false;

        if (!$cache) {

            $storage = new StringStorage($this->compile(file_get_contents($name), $name));

        } else {

            if (!is_file($cache) || filemtime($name) > filemtime($cache)) {
                file_put_contents($cache, $this->compile(file_get_contents($name), $name));
            }

            $storage = new FileStorage($cache);
        }

        return $this->cache[$name] = $storage;
    }

    /**
     * Initializes the extensions.
     *
     * @return void
     */
    protected function initialize()
    {
        foreach ($this->extensions as $extension) {
            $extension->initialize($this);
        }

        $this->initialized = true;
    }
}