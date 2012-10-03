<?php namespace components\update; if(!defined('TX')) die('No direct access.');

class Json extends \dependencies\BaseViews
{
  
  protected function get_update_count($options, $params)
  {
    
    $last_read = tx('Sql')
      ->table('update', 'UserLastReads')
      ->pk(tx('Account')->user->id)
      ->execute_single();
    
    return array(
      'update_count' => tx('Sql')
        ->table('update', 'PackageVersions')
        ->where('date', '>', $last_read->last_read->otherwise(0))
        ->count()
    );
    
  }
  
  /* ---------- Install calls ---------- */
  protected function create_db_test($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('The CMS is not in install mode.');
    
    //Validate input.
    $data = $data->having('host', 'name', 'username', 'password', 'prefix', 'return_connection')
      ->host->validate('Database host', array('required', 'string', 'not_empty'))->back()
      ->username->validate('Username', array('required', 'string', 'not_empty'))->back()
      ->password->validate('Password', array('required', 'string', 'not_empty'))->back()
      ->name->validate('Database name', array('required', 'string', 'not_empty'))->back()
      ->prefix->validate('Table prefix', array('required', 'string', 'not_empty'))->back()
    ;
    
    //Attempt to connect.
    $connection = @mysql_connect($data->host->get(), $data->username->get(), $data->password->get());
    
    //If unable to connect, find out why.
    if(!$connection){
      
      $errno = mysql_errno();
      switch($errno){
        
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
        
        //We don't know o.0"
        default:
          $ex = new \exception\Validation('Unknown MySQL error code');
          $ex->key('host');
          $ex->errors(array('['.$errno.'] '.mysql_error()));
          throw $ex;
        
      }
      
    }
    
    //Check if we can connect to the proper database.
    if(!@mysql_select_db($data->name->get(), $connection)){
      
      $ex = new \exception\Validation('Access denied for database');
      $ex->key('name');
      $ex->errors(array('Access denied, database name may be incorrect'));
      throw $ex;
      
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
    tx('Sql')->set_connection_data(
      $data->host->get(),
      $data->username->get(),
      $data->password->get(),
      $data->name->get(),
      $data->prefix->get()
    );
    
    //Write config file.
    $f = fopen(PATH_BASE.DS.'config'.DS.'database'.EXT, 'w');
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
      throw new \exception\Authorisation('The CMS is not in install mode.');
    
    //Validate input.
    $data = Data($data)->having('site_title', 'email_webmaster', 'email_webmaster_name',
                                'email_automated', 'email_automated_name', 'lang_code', 'lang_shortcode',
                                'paths_base', 'paths_url')
      ->site_title->validate('Site title', array('required', 'string', 'not_empty'))->back()
      ->email_webmaster_name->validate('Webmaster name', array('required', 'string', 'not_empty'))->back()
      ->email_webmaster->validate('Webmaster email', array('required', 'email'))->back()
      ->email_automated_name->validate('Automated messages name', array('required', 'string', 'not_empty'))->back()
      ->email_automated->validate('Automated messages email', array('required', 'email'))->back()
      ->lang_code->validate('Language code', array('required', 'string', 'not_empty'))->back()
      ->lang_shortcode->validate('Short code', array('required', 'string', 'not_empty'))->back()
      ->paths_base->validate('Base path', array('required', 'string', 'not_empty'))->back()
      ->paths_url->validate('Url path', array('required', 'string', 'not_empty'))->back()
    ;
    
    //Write email config file.
    $f = fopen(PATH_BASE.DS.'config'.DS.'email'.EXT, 'w');
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
    
    //Check language code is valid.
    if(!file_exists(PATH_SITE.DS.'languages'.DS.$data->lang_code->get('string').'.ini')){
      $ex = new \exception\Validation('No translation files found for '.PATH_SITE.DS.'languages'.DS.$data->lang_code->get('string').'.ini');
      $ex->key('lang_code');
      $ex->errors(array('No translation files found for this language code'));
      throw $ex;
    }
    
    //There's no viable way to validate base and url paths, that's why it's advanced.
    
    //Since we're in install mode, we need to include the database settings manually.
    require_once(PATH_BASE.DS.'config'.DS.'database'.EXT);
    tx('Sql')->set_connection_data(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PREFIX);
    
    //Store this info.
    tx('Sql')->query("INSERT INTO `#__core_languages` (`code`, `shortcode`) VALUES ('{$data->lang_code}', '{$data->lang_shortcode}')");
    tx('Sql')->query("INSERT INTO `#__core_sites` (`title`, `path_base`, `url_path`) VALUES ('{$data->site_title}', '{$data->paths_base}', '{$data->paths_url}')");
    $site_id = tx('Sql')->get_insert_id();
    tx('Sql')->query("INSERT INTO `#__core_site_domains` (`site_id`, `domain`) VALUES ({$site_id}, '*')");
    tx('Sql')->query("INSERT INTO `#__menu_menus` (`site_id`, `template_key`, `title`) VALUES ('{$site_id}', 'main_menu', '".___('main menu', 'ucfirst')."')");
    
    //Also add a whitelist for all ip addresses. This is a bit too advanced a setting to include into the install script,
    // but needs to be done or nobody can log in from any location.
    tx('Sql')->query("INSERT INTO `#__core_ip_addresses` (`address`, `login_level`) VALUES ('*', 2)");
    
    return array(
      'success' => true,
      'message' => 'Site configuration completed, you can now proceed to the next step.'
    );
    
  }
  
  protected function create_admin_installation($data, $params)
  {
    
    if(INSTALLING !== true)
      throw new \exception\Authorisation('The CMS is not in install mode.');
    
    //Validate input.
    $data = $data->having('email', 'username', 'password')
      ->email->validate('Email address', array('required', 'email'))->back()
      ->username->validate('Username', array('string'))->back()
      ->password->validate('Password', array('required', 'password'))->back()
    ;
    
    //Since we're in install mode, we need to include the database settings manually.
    require_once(PATH_BASE.DS.'config'.DS.'database'.EXT);
    tx('Sql')->set_connection_data(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PREFIX);
    
    //Create this user in the core tables.
    return array(
      'success' => tx('Account')->register($data->email, $data->username, $data->password, 2),
      'message' => 'Administrator account created, you can now finalize the installation.'
    );
    
  }
  
}
