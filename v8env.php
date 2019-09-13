<?php

namespace V8Env;

/**
 * A class that represents a V8 Setting.
 *
 * The setting may have any number of library scripts that will be compiled
 * into a V8Js snapshot for use in a Context instance.
 */
class Setting
{
  /**
   * Set this to the default PHP context object name. The V8Js will add a
   * global property with this name that contains any variables added to it
   * so they can be accessed from Javascript.
   *
   * This can be overridden in individual Context instances.
   */
  public $phpName = 'php';

  /**
   * Set this to the default name you want the property name you want to be
   * added to the PHP context object within the V8 instance for storing
   * extensions for special purposes.
   *
   * This can be overridden in individual Context instances.
   */
  public $extName = '_ext';

  /**
   * If this is true or a string, the addRequire() call will be called
   * automatically on the makeContext() call, using the context name from that.
   */
  public $addRequire = false;

  /**
   * If this is set to true or a Closure, the Context::addLoader() call will
   * be called automatically on the Context::makeV8() or Context::makeEnv()
   * calls. If it's true, the default loadScript will be added. If it's a
   * Closure, that will be used as the loadScript call.
   */
  public $addLoader = false;

  protected $libScripts = [];

  /**
   * Build a Setting object.
   *
   * @param array $opts  Named options for any of the class properties.
   *
   */
  public function __construct ($opts=[])
  {
    $props = array_keys(get_object_vars($this));
    foreach ($props as $prop)
    {
      if (isset($opts[$prop]))
      {
        $this->$prop = $opts[$prop];
      }
    }
  }

  /**
   * Add a library from a string.
   *
   * @param string $string      The string we're adding.
   * @param string $identifier  A name for the string, for later reference.
   *
   * If the $identifier is omitted, we use the whole string, which could be
   * a long name. I don't recommend that!
   */
  public function addString ($string, $identifier=null)
  {
    if (!isset($identifier))
    { // This is a crappy way of doing this, but whatever.
      $identifier = $string;
    }
    $this->libScripts[$identifier] = $string;
    return $this;
  }

  /**
   * Add a library from a file.
   *
   * @param string $filename   The file we want to load.
   */
  public function addFile ($filename)
  {
    $this->libScripts[$identifier] = file_get_contents($filename);
    return $this;
  }

  /**
   * Add a global function to call the 'loadScript' extension function.
   *
   * Instead of calling this manually, you can also just set the
   * $addRequire property to true for the default name, or to a string
   * to override the function name. In that case the context name will be
   * taken from the makeContext() function.
   *
   * @param string $funcName     The name for the function.
   *                             Defaults to 'require'.
   * @param string $phpName      The name for the PHP context object.
   *                             Defaults to $this->phpName if not specified.
   * @param string $extName      The name for the extension object.
   *                             Defaults to $this->extName if not specified.
   *
   */
  public function addRequire ($funcName=null, $phpName=null, $extName=null)
  {
    if (!is_string($funcName)) $funcName = 'require';
    if (!is_string($phpName)) $phpName = $this->phpName;
    if (!is_string($extName)) $extName = $this->extName;
    return $this->addString("\nfunction $funcName (name) { return global.$phpName.$extName.loadScript(name); }\n", $funcName);
  }

  /**
   * Remove a library.
   *
   * @param string $identifier  The identifier or filename previously added.
   */
  public function removeLib ($identifier)
  {
    unset($this->libScripts[$identifier]);
    return $this;
  }

  /**
   * Create a Context instance.
   *
   * @param string $phpName      Will be used as the PHP context object in V8.
   *                             Defaults to $this->phpName if not specified.
   * @param string $extName      Will be used as the extension object name.
   *                             Defaults to $this->extName if not specified.
   *
   * @return V8Env\Context
   */
  public function makeContext ($phpName=null, $extName=null)
  {
    if (is_null($phpName)) $phpName = $this->phpName;
    if (is_null($extName)) $extName = $this->extName;

    if ($this->addRequire)
    {
      $this->addRequire($this->addRequire, $phpName, $extName);
    }

    $libText = implode("\n", $this->libScripts);
    $snapshot = \V8Js::createSnapshot($libText);
    return new Context($this, $phpName, $extName, $snapshot);
  }
}

/**
 * A class representing a V8 Context.
 *
 * Don't manually build this, use Setting::makeContext() instead.
 */
class Context
{
  protected $setting;
  protected $phpName;
  protected $extName;
  protected $snapshot;
  protected $extensions = [];

  public function __construct ($setting, $phpName, $extName, $snapshot)
  {
    $this->setting = $setting;
    $this->phpName = $phpName;
    $this->extName = $extName;
    $this->snapshot = $snapshot;
  }

  /**
   * Return our parent Setting instance.
   */
  public function getSetting ()
  {
    return $this->setting;
  }

  /**
   * Return the underlying snapshot object.
   */
  public function getSnapshot ()
  {
    return $this->snapshot;
  }

  public function getPhpName ()
  {
    return $this->phpName;
  }

  public function getExtName ()
  {
    return $this->extName;
  }

  /**
   * Add an extension to our PHP context object.
   *
   * These are NOT V8Js extensions (which are deprecated), they are simply
   * PHP objects, properties, or functions which can be used for internal
   * library purposes. They are put into a child property within the global
   * PHP context object to keep them separate from user added properties.
   *
   * If the property is a Closure, when the makeV8() or makeEnv() method
   * is called, we will make a copy of the Closure bound to the spawned
   * object. That way you can call $this on any public function calls.
   */
  public function __set ($name, $value)
  {
    $this->extensions[$name] = $value;
  }

  /**
   * Remove an extension property.
   */
  public function __unset ($name)
  {
    unset($this->extensions[$name]);
  }

  /**
   * Get an extension property.
   */
  public function __get ($name)
  {
    return $this->extensions[$name];
  }

  /**
   * Return a V8Js instance.
   *
   * @param bool $extend    Add extensions to the V8Js PHP object.
   *                        Default: true.
   *
   * @return V8Js
   */
  public function makeV8 ($extend=true)
  {
    $v8 = new \V8Js($this->phpName, [], [], true, $this->snapshot);
    if ($extend)
    {
      $this->addExtensions($v8);
    }
    return $v8;
  }

  /**
   * Return a Environment instance.
   *
   * Calls makeV8() and then returns a new Environment instance with it.
   *
   * @param bool $extend      Add extensions to the Environment instance.
   *                          Default: true.
   *                          This is mutually exclusive with $extendV8.
   * @param bool $extendV8    Add extensions to the V8Js instance.
   *                          Default: false.
   *                          This is mutually exclusive with $extend.
   *
   * @return V8Env\Environment
   */
  public function makeEnv ($extend=true, $extendV8=false)
  {
    if ($extend && $extendV8)
    {
      throw new Exception("extend and extendV8 are mutually exclusive");
    }
    $v8 = $this->makeV8($extendV8);
    $env = new Environment($this, $v8);
    if ($extend)
    {
      $this->addExtensions($env);
    }
    return $env;
  }

  /**
   * Add a script loader extension property.
   *
   * It uses the name 'loadScript' which is used by the 'require()' built-in.
   *
   * If the Setting::$addLoader property is set, and this has not been
   * called manually, it will be called automatically.
   *
   * @param Closure $script  The closure script to perform the loading.
   *
   * If $script is not a Closure, we will add a default version that uses 
   * local files. When makeV8() or makeEnv() is called, a copy of the closure
   * will be bound to the spawned object.
   */
  public function addLoader ($script=null)
  {
    if (!($script instanceof \Closure))
    {
      $script = function ($filename)
      {
        if (is_callable([$this, 'runFile']))
        {
          return $this->runFile($filename);
        }
        elseif (is_callable([$this, 'executeString']))
        {
          return $this->executeString(file_get_contents($filename), $filename);
        }
        else
        {
          throw new \Exception("Could not find execute method");
        }
      };
    }
    $this->extensions['loadScript'] = $script;
    return $this;
  }

  protected function addExtensions ($obj)
  {
    if ($this->setting->addLoader && !isset($this->extensions['loadScript']))
    {
      $this->addLoader($this->setting->addLoader);
    }

    if (count($this->extensions) == 0)
    { // No extensions, we won't do anything.
      return;
    }

    $env = [];
    foreach ($this->extensions as $name => $val)
    {
      if ($val instanceof \Closure)
      {
        $env[$name] = $val->bindTo($obj);
      }
      else
      {
        $env[$name] = $val;
      }
    }

    $extName = $this->extName;
    $obj->$extName = $env;
  }
}

/**
 * Represents a V8 Environment.
 *
 * This is the final wrapper around the V8Js class.
 * Don't manually build this, use Context::makeEnv() instead.
 */
class Environment
{
  protected $context;
  protected $v8;

  public function __construct ($context, $v8)
  {
    $this->context = $context;
    $this->v8 = $v8;
  }

  /**
   * Return our parent Context instance.
   */
  public function getContext ()
  {
    return $this->context;
  }

  /**
   * Return the underlying V8Js instance.
   */
  public function getV8 ()
  {
    return $this->v8;
  }

  /**
   * Set a V8Js property.
   */
  public function __set ($name, $val)
  {
    $extName = $this->context->getExtName();
    if ($name == $extName && isset($this->v8->$name))
    {
      throw new \Exception("Cannot override '$extName' property");
    }
    $this->v8->$name = $val;
  }

  /**
   * Get a V8Js property.
   */
  public function __get ($name)
  {
    return $this->v8->$name;
  }

  /**
   * Unset a V8Js property.
   */
  public function __unset ($name)
  {
    $extName = $this->context->getExtName();
    if ($name == $extName)
    {
      throw new \Exception("Cannot unset '$extName' property");
    }
    unset($this->v8->$name);
  }

  /**
   * Run a string as a script.
   *
   * @param string $text  The script text you want to run.
   * @param array  $args  Named arguments you want to add.
   */
  public function runString ($text, $args=[], $identifier='', $flags=\V8JS::FLAG_NONE, $tl=0, $ml=0)
  {
    foreach ($args as $argname => $argval)
    {
      $this->v8->$argname = $argval;
    }
    return $this->v8->executeString($text, $identifier, $flags, $tl, $ml);
  }

  /**
   * Run a file as a script.
   *
   * @param string $filename  The filename of the script to run.
   * @param array  $args      Named arguments you want to add.
   */
  public function runFile ($filename, $args=[], $flags=\V8JS::FLAG_NONE, $tl=0, $ml=0)
  {
    return $this->runString(file_get_contents($filename), $args, $filename, $flags, $tl, $ml);
  }
}
