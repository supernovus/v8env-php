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
   * If this is true or a string, the addRequire() call will be called
   * automatically on the makeContext() call, using the context name from that.
   */
  public $addRequire = false;

  /**
   * If this is set to true or a Closure, the Context::addLoader() call will
   * be called automatically on the Context::makeV8() or Context::makeEnv()
   * calls. If it's true, the default addLoader will be added. If it's a
   * Closure, that will be used as the addLoader call.
   */
  public $addLoader = false;

  protected $libScripts = [];

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
   * Add a global function to call the 'loadScript' environment function.
   *
   * Instead of calling this manually, you can also just set the
   * $addRequire property to true for the default name, or to a string
   * to override the function name. In that case the context name will be
   * taken from the makeContext() function.
   *
   * @param string $funcName  The name for the function (default 'require').
   * @param string $contextName  The name for the context (default 'php').
   */
  public function addRequire ($funcName='require', $contextName='php')
  {
    return $this->addString("\nfunction $funcName (name) { return global.$contextName._environment.loadScript(name); }\n", $funcName);
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
   * @param string $contextName  Will be used as the PHP context object in V8.
   *
   * Defaults to 'php' if not specified.
   *
   * @return V8Env\Context
   */
  public function makeContext ($contextName='php')
  {
    if ($this->addRequire)
    {
      if (is_string($this->addRequire))
      {
        $this->addRequire($this->addRequire, $contextName);
      }
      else
      {
        $this->addRequire('require', $contextName);
      }
    }
    $libText = implode("\n", $this->libScripts);
    $snapshot = \V8Js::createSnapshot($libText);
    return new Context($this, $contextName, $snapshot);
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
  protected $contextName;
  protected $snapshot;
  protected $envScripts = [];

  public function __construct ($setting, $contextName, $snapshot)
  {
    $this->setting = $setting;
    $this->contextName = $contextName;
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

  /**
   * Add a property to our '_environment' PHP context object.
   *
   * If the property is a Closure, when the makeV8() or makeEnv() method
   * is called, we will make a copy of the Closure bound to the spawned
   * object. That way you can call $this on any public function calls.
   */
  public function __set ($name, $value)
  {
    $this->envScripts[$name] = $value;
  }

  /**
   * Remove a property from our '_environment' PHP context object.
   */
  public function __unset ($name)
  {
    unset($this->envScripts[$name]);
  }

  /**
   * Get a property from our '_environment' PHP context object.
   */
  public function __get ($name)
  {
    return $this->envScripts[$name];
  }

  /**
   * Return a V8Js instance.
   *
   * @param bool $populate  Populate the _environment using the V8Js.
   *                        Default: true.
   *
   * @return V8Js
   */
  public function makeV8 ($populate=true)
  {
    $v8 = new \V8Js($this->contextName, [], [], true, $this->snapshot);
    if ($populate)
    {
      $this->populateEnvironment($v8);
    }
    return $v8;
  }

  /**
   * Return a Environment instance.
   *
   * Calls makeV8() and then returns a new Environment instance with it.
   *
   * @param bool $populate    Populate the _environment using the Environment.
   *                          Default: true.
   *                          This is mutually exclusive with $populateV8.
   * @param bool $populateV8  Populate the _environment using the V8Js.
   *                          Default: false.
   *                          This is mutually exclusive with $populate.
   *
   * @return V8Env\Environment
   */
  public function makeEnv ($populate=true, $populateV8=false)
  {
    if ($populate && $populateV8)
    {
      throw new Exception("populate and populateV8 are mutually exclusive");
    }
    $v8 = $this->makeV8($populateV8);
    $env = new Environment($this, $v8);
    if ($populate)
    {
      $this->populateEnvironment($env);
    }
    return $env;
  }

  /**
   * Add a script loader to the environment scripts.
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
    $this->envScripts['loadScript'] = $script;
    return $this;
  }

  protected function populateEnvironment ($obj)
  {
    if ($this->setting->addLoader && !isset($this->envScripts['loadScript']))
    {
      $this->addLoader($this->setting->addLoader);
    }

    $env = [];
    foreach ($this->envScripts as $name => $val)
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

    $obj->_environment = $env;
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
