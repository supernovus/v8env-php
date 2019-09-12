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
   * If this is set to true, we will add a 'loadScript' function to the
   * '_environment' PHP context property.
   */
  public $addLoader = false;

  /**
   * If this and $addLoader are both true, we will add a 'require' global
   * function that uses the 'loadScript' function to load scripts.
   */
  public $addRequire = false;

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
  }

  /**
   * Add a library from a file.
   *
   * @param string $filename   The file we want to load.
   */
  public function addFile ($filename)
  {
    $this->libScripts[$identifier] = file_get_contents($filename);
  }

  /**
   * Remove a library.
   *
   * @param string $identifier  The identifier or filename previously added.
   */
  public function removeLib ($identifier)
  {
    unset($this->libScripts[$identifier]);
  }

  /**
   * Create a Context instance.
   *
   * @param string $contextName  Will be used as the PHP context object in V8.
   *
   * @return V8Env\Context
   */
  public function makeContext ($contextName)
  {
    $libText = implode("\n", $this->libScripts);
    if ($this->addLoader && $this->addRequire)
    {
      $libText .= "\nfunction require (name) { return global.$contextName._environment.loadScript(name); }\n";
    }
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
   * Return a V8Js instance.
   *
   * Automatically sets the context name, and snapshot, and populates
   * a special '_environment' property in the PHP context object which contains
   * useful helper functions.
   *
   * @return V8Js
   */
  public function makeV8 ()
  {
    $v8 = new \V8Js($this->contextName, [], [], true, $this->snapshot);
    $this->populateEnvironment($v8);
    return $v8;
  }

  /**
   * Return a Environment instance.
   *
   * Calls makeV8() and then returns a new Environment instance with it.
   *
   * @return V8Env\Environment
   */
  public function makeEnv ()
  {
    $v8 = $this->makeV8();
    return new Environment($this, $v8);
  }

  protected function populateEnvironment ($v8)
  {
    $envlib = [];
    if ($this->setting->addLoader)
    {
      $envlib['loadScript'] = function ($filename) use ($v8)
      {
        return $v8->executeString(file_get_contents($filename), $filename);
      };
    }
    $v8->_environment = $envlib;
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
