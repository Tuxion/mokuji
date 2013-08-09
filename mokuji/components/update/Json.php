<?php namespace components\update; if(!defined('TX')) die('No direct access.');

use \PDO;
use components\update\tasks\CoreUpdates;

class Json extends \dependencies\BaseViews
{
  
  protected function get_update_count($options, $params)
  {
    
    $last_read = mk('Sql')
      ->table('update', 'UserLastReads')
      ->pk(mk('Account')->user->id)
      ->execute_single();
    
    return array(
      'update_count' => mk('Sql')
        ->table('update', 'PackageVersions')
        ->where('date', '>', $last_read->last_read->otherwise(0))
        ->count()
    );
    
  }
  
  /* ---------- Install and upgrade calls ---------- */
  protected function create_config_upgrade($data, $params)
  {
    
    //Check the database connection first.
    $connection = $this->create_db_test($data, $params);
    
    //Get all the values we need for email settings.
    $data = Data($data)->having(
      'host', 'username', 'password', 'name', 'prefix',
      'email_webmaster', 'email_webmaster_name',
      'email_automated', 'email_automated_name'
    )
    
    //Validate each of these values.
    ->email_webmaster_name->validate('Webmaster name', array('required', 'string', 'not_empty'))->back()
    ->email_webmaster->validate('Webmaster email', array('required', 'email'))->back()
    ->email_automated_name->validate('Automated messages name', array('required', 'string', 'not_empty'))->back()
    ->email_automated->validate('Automated messages email', array('required', 'email'))->back();
    
    //Set the connection data in SQL class.
    mk('Sql')->set_connection_data(
      $data->host->get(),
      $data->username->get(),
      $data->password->get(),
      $data->name->get(),
      $data->prefix->get()
    );
    
    //Write database config file.
    $f = fopen(PATH_FRAMEWORK.DS.'config'.DS.'database'.EXT, 'w');
    fwrite($f,
      '<?php if(!defined(\'MK\')) die(\'No direct access.\');'.n.
      '//Autogenerated database config file.'.n.
      '//Created: '.date('Y-m-d H:i:s').n.
      '//Generator: ?rest=update/create_config_upgrade'.n.
      'define("DB_HOST","'.$data->host.'");'.n.
      'define("DB_USER","'.$data->username.'");'.n.
      'define("DB_PASS","'.$data->password.'");'.n.
      'define("DB_NAME","'.$data->name.'");'.n.
      'define("DB_PREFIX","'.$data->prefix.'");'
    );
    
    //Write email config file.
    $f = fopen(PATH_FRAMEWORK.DS.'config'.DS.'email'.EXT, 'w');
    fwrite($f,
      '<?php if(!defined(\'MK\')) die(\'No direct access.\');'.n.
      '//Autogenerated email config file.'.n.
      '//Created: '.date('Y-m-d H:i:s').n.
      '//Generator: ?rest=update/create_config_upgrade'.n.
      'define("EMAIL_NAME_WEBMASTER","'.$data->email_webmaster_name.'");'.n.
      'define("EMAIL_ADDRESS_WEBMASTER","'.$data->email_webmaster.'");'.n.
      'define("EMAIL_NAME_AUTOMATED_MESSAGES","'.$data->email_automated_name.'");'.n.
      'define("EMAIL_ADDRESS_AUTOMATED_MESSAGES","'.$data->email_automated.'");'
    );
    
    return array(
      'success' => true,
      'message' => "The configuration has been saved, you can now proceed to the next step."
    );
    
  }
  
  protected function create_files_scan($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('Mokuji is not in install mode.');
    
    return array(
      'success' => true,
      'files' => CoreUpdates::suggest_file_transfer_actions()
    );
    
  }
  
  protected function create_files_transfer($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('Mokuji is not in install mode.');
    
    return array(
      'success' => true,
      'completed' => CoreUpdates::execute_file_transfer_actions($data->files)
    );
    
  }
  
  protected function create_db_test($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('Mokuji is not in install mode.');
    
    //Validate input.
    $data = $data->having('host', 'name', 'username', 'password', 'prefix', 'return_connection')
      ->host->validate('Database host', array('required', 'string', 'not_empty'))->back()
      ->username->validate('Username', array('required', 'string', 'not_empty'))->back()
      ->password->validate('Password', array('required', 'string', 'not_empty'))->back()
      ->name->validate('Database name', array('required', 'string', 'not_empty'))->back()
      ->prefix->validate('Table prefix', array('required', 'string', 'not_empty'))->back()
    ;
    
    //Attempt to connect.
    $connection = null;
    try{
      $connection = new PDO(
        'mysql:host='.$data->host->get().';dbname='.$data->name->get(),
        $data->username->get(), $data->password->get()
      );
    }
    
    catch(\PDOException $pdoex){
      
      $errorCode = $pdoex->errorInfo === null ?
        $pdoex->getCode():
        $pdoex->errorInfo[1];
      
      switch($errorCode){
        
        //Access denied.
        case 1045:
          $ex = new \exception\Validation('Access denied for user');
          $ex->key('username');
          $ex->errors(array('Access denied, username or password may be incorrect'));
          throw $ex;
        
        //Unknown host.
        case 2005:
          $ex = new \exception\Validation('Unknown MySQL server host');
          $ex->key('host');
          $ex->errors(array('Unknown MySQL server host'));
          throw $ex;
        
        //Database access denied.
        case 1044:
          $ex = new \exception\Validation('Access denied for database');
          $ex->key('name');
          $ex->errors(array('Database access denied, database name may be incorrect'));
          throw $ex;
        
        //We don't know o.0"
        default:
          $ex = new \exception\Validation('Unknown MySQL error code');
          $ex->key('host');
          $ex->errors(
            $errorInfo === null ?
              array($pdoex->getMessage()) :
              array('['.$errorInfo[1].'] '.$errorInfo[2])
          );
          throw $ex;
        
      }
      
    }
    
    //In case we don't want to repeat the whole thing.
    if($data->return_connection->is_true())
      return $connection;
    
    return array(
      'success' => true,
      'message' => "The provided information works, you can apply it."
    );
    
  }
  
  protected function create_db_installation($data, $params)
  {
    
    //Check the database connection first.
    $connection = $this->create_db_test($data, $params);
    
    //Set the connection data in SQL class.
    mk('Sql')->set_connection_data(
      $data->host->get(),
      $data->username->get(),
      $data->password->get(),
      $data->name->get(),
      $data->prefix->get()
    );
    
    //Write config file.
    $f = fopen(PATH_FRAMEWORK.DS.'config'.DS.'database'.EXT, 'w');
    fwrite($f,
      '<?php if(!defined(\'TX\')) die(\'No direct access.\');'.n.
      '//Autogenerated database config file.'.n.
      '//Created: '.date('Y-m-d H:i:s').n.
      '//Generator: ?rest=update/create_db_installation'.n.
      'define("DB_HOST","'.$data->host.'");'.n.
      'define("DB_USER","'.$data->username.'");'.n.
      'define("DB_PASS","'.$data->password.'");'.n.
      'define("DB_NAME","'.$data->name.'");'.n.
      'define("DB_PREFIX","'.$data->prefix.'");'
    );
    
    //Now install update component tables.
    require_once(PATH_COMPONENTS.DS.'update'.DS.'.package'.DS.'DBUpdates'.EXT);
    $updater = new \components\update\DBUpdates();
    $success = $updater->install(false, true, true) && $this->helper('check_updates', array('silent'=>true, 'force'=>true));
    
    return array(
      'success' => $success,
      'message' => 'Database installation finished, you can now proceed to the next step.'
    );
    
  }
  
  protected function create_site_installation($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('Mokuji is not in install mode.');
    
    //Since we're in install mode, we need to include the database settings manually.
    require_once(PATH_FRAMEWORK.DS.'config'.DS.'database'.EXT);
    mk('Sql')->set_connection_data(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PREFIX);
    
    //Get all the values we need.
    $data = Data($data)->having(
      'site_title', 'email_webmaster', 'email_webmaster_name',
      'email_automated', 'email_automated_name', 'lang_code',
      'lang_title', 'lang_shortcode', 'paths_base', 'paths_url'
    )
    
    //Validate each of these values.
    ->site_title->validate('Site title', array('required', 'string', 'not_empty'))->back()
    ->email_webmaster_name->validate('Webmaster name', array('required', 'string', 'not_empty'))->back()
    ->email_webmaster->validate('Webmaster email', array('required', 'email'))->back()
    ->email_automated_name->validate('Automated messages name', array('required', 'string', 'not_empty'))->back()
    ->email_automated->validate('Automated messages email', array('required', 'email'))->back()
    ->lang_title->validate('Language title', array('required', 'string', 'not_empty'))->back()
    ->lang_code->validate('Language code', array('required', 'string', 'not_empty'))->back()
    ->lang_shortcode->validate('Short code', array('required', 'string', 'not_empty'))->back()
    ->paths_base->validate('Base path', array('required', 'string', 'not_empty'))->back()
    ->paths_url->validate('Url path', array('string'))->back();
    
    //Write email config file.
    $f = fopen(PATH_FRAMEWORK.DS.'config'.DS.'email'.EXT, 'w');
    fwrite($f,
      '<?php if(!defined(\'TX\')) die(\'No direct access.\');'.n.
      '//Autogenerated email config file.'.n.
      '//Created: '.date('Y-m-d H:i:s').n.
      '//Generator: ?rest=update/create_site_installation'.n.
      'define("EMAIL_NAME_WEBMASTER","'.$data->email_webmaster_name.'");'.n.
      'define("EMAIL_ADDRESS_WEBMASTER","'.$data->email_webmaster.'");'.n.
      'define("EMAIL_NAME_AUTOMATED_MESSAGES","'.$data->email_automated_name.'");'.n.
      'define("EMAIL_ADDRESS_AUTOMATED_MESSAGES","'.$data->email_automated.'");'
    );
    
    //Check language code valid is.
    if(!file_exists(PATH_SYSTEM.DS.'i18n'.DS.$data->lang_code->get('string').'.json')){
      $ex = new \exception\Validation('No translation files found for '.PATH_SYSTEM.DS.'i18n'.DS.$data->lang_code->get('string').'.json');
      $ex->key('lang_code');
      $ex->errors(array('No translation files found for this language code'));
      throw $ex;
    }
    
    //There's no viable way to validate base and url paths, that's why it's advanced.
    
    //Store this info.
    mk('Sql')->query("INSERT INTO `#__core_languages` (`code`, `shortcode`, `title`) VALUES ('{$data->lang_code}', '{$data->lang_shortcode}', '{$data->lang_title}')");
    mk('Sql')->query("INSERT INTO `#__core_sites` (`title`, `path_base`, `url_path`) VALUES ('{$data->site_title}', '{$data->paths_base}', '{$data->paths_url}')");
    $site_id = mk('Sql')->get_insert_id();
    mk('Sql')->query("INSERT INTO `#__core_site_domains` (`site_id`, `domain`) VALUES ({$site_id}, '*')");
    mk('Sql')->query("INSERT INTO `#__menu_menus` (`site_id`, `template_key`, `title`) VALUES ('{$site_id}', 'main_menu', '".___('main menu', 'ucfirst')."')");
    
    //Also add a whitelist for all ip addresses. This is a bit too advanced a setting to include into the install script,
    // but needs to be done or nobody can log in from any location.
    mk('Sql')->query("INSERT INTO `#__core_ip_addresses` (`address`, `login_level`) VALUES ('*', 2)");
    
    return array(
      'success' => true,
      'message' => 'Site configuration completed, you can now proceed to the next step.'
    );
    
  }
  
  protected function create_admin_installation($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('Mokuji is not in install mode.');
    
    //Validate input.
    $data = $data->having('email', 'username', 'password')
      ->email->validate('Email address', array('required', 'email'))->back()
      ->username->validate('Username', array('string'))->back()
      ->password->validate('Password', array('required', 'password'))->back()
    ;
    
    //Since we're in install mode, we need to include the database settings manually.
    require_once(PATH_FRAMEWORK.DS.'config'.DS.'database'.EXT);
    mk('Sql')->set_connection_data(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PREFIX);
    
    //Create this user in the core tables.
    return array(
      'success' => mk('Account')->register($data->email, $data->username, $data->password, 2),
      'message' => 'Administrator account created, you can now finalize the installation.'
    );
    
  }
  
}
