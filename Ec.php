<?php
namespace PhpErr2Exc;

/**
 * PHP has two error flagging mechanisms, the old triggered type - called errors herein, and newer exceptions.
 * PHP provides ErrorExpection to allow you map errors to exceptions. This PHP file sets that up for you.
 * Only some of the user handleable errors are rethrown as exceptions. This is configured by EC_XXX defines below.
 * @see http:// au2.php.net/manual/en/class.errorexception.php, http://au2.php.net/manual/en/errorfunc.configuration.php, README.md
 */
define("EC_RETHROW", E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR);
define("EC_DIE", E_USER_ERROR);
define("EC_FATAL", E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

class Ec
{
  public static $EC_LOG_RETHROWN = false;
  public static $EC_RETHROW = EC_RETHROW;
  public static $EC_DIE = EC_DIE;

  /**
   * Handle all user handle-able errors, and reroute Errors to Exceptions appropriately - see above.
   * Attemped reimplementation of inbuilt for non thrown. All error logging is handled according to normal methods.
   *  Note:
   *    "It is important to remember that the standard PHP error handler is completely bypassed." - PHP Manual.
   *    "If an user error handler successfully handles an error then that error will not be reported by this error_get_last()." - PHP Manual.
   * Unfortunately cant pass the errcontext into the ErrorException in any way. so that trace is lost, unless you define EC_LOG_RETHROWN.
   */
  public static function ec_error_handler($errno, $errmsg, $errfile, $errline, $errcontext)
  {
    // Log the error according to PHP_INI settings. ec_re_error_log() is a reimp of error_log().
    Ec::ec_re_error_log($errno, $errmsg, $errfile, $errline);

    // PHP will not set error_get_last() for overridden errors - "completely bypassed".
    // do this after ec_re_error_log() coz that causes E_STRICT.
    Ec::ec_set_error_get_last($errno, $errmsg, $errfile, $errline, $errcontext);

    // Reroute the condition as described above.
    if(self::$EC_RETHROW & $errno)
    {
       throw new \ErrorException($errmsg, $errno, $errno, $errfile, $errline); // ErrorException::__construct ($message [, $code [, $severity [, $filename [, $lineno ]]]]]);
    }
    // Any shutdown_functions will be called as per usual.
    elseif(self::$EC_DIE & $errno)
    {
      exit(1);
    }
    else
    {
      return true;
    }
  }


  /**
   * Reimplementation ~same as built-in error handler bar stack trace which you don’t really get with Exceptions.
   * If EC_LOG_RETHROWN defined event is logged regardless of whether we decide to throw.
   * Most of the work is done by error_log().
   * @returns bool true iff logged error.
   */
  public static function ec_re_error_log($errno, $errmsg, $errfile, $errline)
  {
    $logged = false; // whether a log was made.
    if($errno & error_reporting())
    {
      // Yes, 'log_errors' and 'display_errors' are independent.
      // "$errmsg is sent to PHP's system logger, using the Operating System's system logging mechanism or a file,
      //  depending on what the error_log  configuration directive is set to. This is the default option."
      // 'display_errors' is pretty much just a switch - put *any* reportable errors on the output device.
      if(ini_get("log_errors"))
      {
        // adds some stuff to beginning/end of $errmsg.
        // option for not logging errors that will be thrown.
        if(($errno & ~self::$EC_RETHROW) || self::$EC_LOG_RETHROWN)
        {
            error_log(Ec::ec_make_error_log($errno, $errmsg, $errfile, $errline));
            $logged = true;
        }
      }

      //"Value 'stderr' sends the errors to stderr instead of stdout.
      // The value is available as of PHP 5.2.4. In earlier versions, this directive was of type boolean."
      if(ini_get("display_errors"))
      {
        if(ini_get("display_errors") == "stderr")
        {
          $f_out = fopen("php:// stderr", "w");
          fwrite($f_out, ini_get("error_prepend_string") . self::ec_make_error_log($errno, $errmsg, $errfile, $errline) . ini_get("error_append_string"));
        }
        else
        {
          print ini_get("error_prepend_string") . self::ec_make_error_log($errno, $errmsg, $errfile, $errline) . ini_get("error_append_string");
        }
      }
    }
    return $logged;
  }


  /**
   * Reimplement built-in handlers output format.
   * as of PHP 5.3.
   */
  public static function ec_make_error_log($errno, $errmsg, $errfile, $errline)
  {
    // Some of these errors can not occur here. Copied from PHP manual and added the 2 XXX_DEPRECATED types.
    $errnotices = array (
        E_ERROR              => 'Fatal Error',  // nh,nr //modified string from 'Error'.
        E_WARNING            => 'Warning',
        E_PARSE              => 'Parsing Error',  // nh,nr
        E_NOTICE             => 'Notice',
        E_CORE_ERROR         => 'Core Error',  // nh,nr
        E_CORE_WARNING       => 'Core Warning',  // nh
        E_COMPILE_ERROR      => 'Compile Error',  // nh,nr
        E_COMPILE_WARNING    => 'Compile Warning',
        E_USER_ERROR         => 'User Error',  //#nr
        E_USER_WARNING       => 'User Warning',
        E_USER_NOTICE        => 'User Notice',
        E_STRICT             => 'Runtime Notice',  //#nh
        E_RECOVERABLE_ERROR  => 'Catchable Fatal Error'
        // E_DEPRECATED         => 'Deprecated Notice',
        // E_USER_DEPRECATED    => 'User Deprecated Notice',
       );
    $errprep = ((array_key_exists($errno, $errnotices)) ? $errnotices[$errno] : 'Unknown Error');
    return "PHP ".$errprep.": ".$errmsg." in $errfile on line $errline";
  }


  /**
   * Sets the global $error_get_last. Used because PHP does not do it for us when overriding error handler.
   * This function can also be used to stuff an exception into error_get_last.
   * @param $errcontext maybe an Array or an Exception depending on what the last error was.
   */
  public static function ec_set_error_get_last($errno, $errmsg, $errfile, $errline, $errcontext, $was_exception = false)
  {
    global $error_get_last;
    $error_get_last =
    array
    (
      'type' => $errno,
      'message' => $errmsg,
      'file' => $errfile,
      'line' => $errline,
      'context' => $errcontext,
      'was_exception' => $was_exception
   );
  }


  /**
   * Init. Such that can reset state.
   */
  public static function init()
  {
    self::$EC_LOG_RETHROWN = false;
    self::$EC_RETHROW = EC_RETHROW;
    self::$EC_DIE = EC_DIE;
    set_error_handler(array("\PhpErr2Exc\Ec", "ec_error_handler"));
  }
}
?>