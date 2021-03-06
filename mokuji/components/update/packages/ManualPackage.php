<?php namespace components\update\packages; if(!defined('MK')) die('No direct access.');

use \components\update\enums\PackageType;

class ManualPackage extends AbstractPackage
{
  
  /**
   * The type ID used in the packages table.
   * @var int
   */
  const TYPE_ID = 0;
  
  /**
   * The latest version as defined by the raw package data.
   * @var string
   */
  protected $latest_version;
  
  /**
   * Whether or not the raw package data indicates to contain database updates.
   * @var boolean
   */
  protected $db_updates;
  
  /**
   * Checks whether the requirements are met for this type of Package.
   * @return boolean
   */
  public static function check($type, $name=null)
  {
    
    //There should be a package.json file in the .package directory for this type.
    $directory = PackageFactory::directory($type, $name);
    return is_file($directory.DS.'.package'.DS.'package.json');
    
  }
  
  /**
   * Create a new instance.
   * @param \components\update\enums\PackageType $type The type of package the instance will refer to.
   * @param string $name The name of the package the instance will refer to.
   */
  public function __construct($type, $name=null)
  {
    
    parent::__construct($type, $name);
    
    if(!$this->raw_data()->type->get('string') === 'manual')
      throw new \exception\Programmer('ManualPackage class used for a package of type %s', $this->raw_data()->type->get('string'));
    
    //Check whether database updates are present.
    $this->db_updates = $this->raw_data()->dbUpdates->get('boolean');
    
  }
  
  /**
   * Update the update system information to match the package information.
   * @return boolean Whether or not syncing was completed successfully.
   */
  public function synchronize(){
    mk('Logging')->log('ManualPackage', 'Syncing', $this->raw_data()->title);
    return parent::synchronize();
  }
  
  /**
   * Perform an update to the latest version of the package.
   * @param  boolean $forced     Forced update?
   * @param  boolean $allow_sync Syncing allowed?
   * @return boolean Whether or not new versions were installed.
   */
  public function update($forced=false, $allow_sync=false)
  {
    
    if($allow_sync)
      $this->synchronize();
    
    //If we have a next version to update to.
    if($this->next_version() !== false || $this->current_version() === ''){
      
      //And we have DB updates...
      if($this->db_updates()){
        return $this->db_updates()->update($forced, true);
      }
      
      //Otherwise go straight to the target version.
      else {
        return $this->version_bump($this->next_version());
      }
      
    }
    
    return false;
    
  }
  
  /**
   * Retrieves the reference ID of this package.
   * @return string The reference ID of this package.
   */
  public function reference_id()
  {
    
    //Check our own records first.
    if(isset($this->reference_id))
      return $this->reference_id;
    
    //Where the reference at?
    $reference_file = $this->directory().DS.'.package'.DS.'reference-id';
    
    //If the file is not there, we do not have a reference ID yet.
    if(!is_file($reference_file))
      return null;
    
    //Otherwise, cache it, since it's not going to change often.
    $this->reference_id = file_get_contents($reference_file);
    return $this->reference_id;
    
  }
  
  /**
   * Retrieves the raw package data from the package files.
   * @return \dependencies\Data The raw package data.
   */
  public function raw_data()
  {
    
    //Check our cache first.
    if(isset($this->raw_data))
      return $this->raw_data;
    
    //Where the package at?
    $package_file = $this->directory().DS.'.package'.DS.'package.json';
    
    //Make sure the package file is there.
    if(!is_file($package_file))
      throw new \exception\FileMissing('Package does not contain a package.json file at %s', $package_file);
    
    //Get the package data.
    $this->raw_data = Data(json_decode(file_get_contents($package_file), true));
    return $this->raw_data;
    
  }
  
  /**
   * Determines the next version that should be installed in the update order defined.
   * @param  string $version The version that is currently installed.
   * @return string The version that should be installed next.
   */
  public function next_version($version=null)
  {
    
    //When there are no DB updates, there is no upgrade order.
    if(!$this->db_updates())
    {
      
      //If we're already at the latest version, don't do anything.
      if($this->current_version() == $this->latest_version())
        return false;
      
      //Otherwise just go straight to the latest.
      else
        return $this->latest_version();
      
    }
    
    //When there are, delegate this to the DB updates class.
    return $this->db_updates()->next_version($version);
    
  }
  
  /**
   * Tracks a version update of the package.
   * Note: $allow_sync should only be set to true to allow the update component to install itself.
   * @param string $version The version of the package that is now installed.
   * @param boolean $allow_sync Whether or not to allow the package to be synced, to obtain version information.
   * @return boolean Whether or not the version update was successful.
   */
  public function version_bump($version, $allow_sync=false)
  {
    
    $self = $this;

    raw($version);
    
    //We need to clear this cache regularly, because otherwise this may mess up the ORM during install.
    if($this->db_updates()){
      \dependencies\BaseModel::clear_table_data_cache();
    }
    
    //In case of a self-install the package will not be inserted yet.
    if($allow_sync){
      
      //Update the version data from the package.json.
      $this->synchronize();
      
    }
    
    //Normal version bump.
    $version = mk('Sql')
      ->table('update', 'PackageVersions')
      ->where('package_id', $this->model()->id)
      ->where('version', "'{$version}'")
      ->execute_single()
      ->is('empty', function()use($self, $version){
        throw new \exception\NotFound('Version '.$version.' is not defined for package '.$self->model()->title);
      });
    
    //Do the bump.
    $this->model()->merge(array(
      'installed_version' => $version->version,
      'installed_version_date' => $version->date
    ))->save();
    
    return true;
    
  }
  
  /**
   * Gets an instance of the DBUpdates class associated with this package, or null if DBUpdates are not used.
   * @return mixed The DBUpdates instance or null.
   */
  public function db_updates()
  {
    
    //When no DBUpdates are desired by the package, return null.
    if(!$this->db_updates)
      return null;
    
    //Include the DBUpdates class file.
    require_once($this->directory().DS.'.package'.DS.'DBUpdates'.EXT);
    
    //Depending on the type, get it's instance.
    switch ($this->type) {
      case PackageType::CORE:       return new \core\DBUpdates();
      case PackageType::COMPONENT:  $class = "\\components\\{$this->name}\\DBUpdates"; return new $class();
      case PackageType::TEMPLATE:   $class = "\\templates\\{$this->name}\\DBUpdates"; return new $class();
      case PackageType::THEME:      $class = "\\themes\\{$this->name}\\DBUpdates"; return new $class();
      default: throw new \exception\Programmer('Invalid PackageType value '.$type);
    }
    
  }
  
  /**
   * Gets a model instance, referencing this package.
   * @return \components\update\models\Packages
   */
  public function model()
  {
    
    $self = $this;

    //Do some caching.
    if($this->model) return $this->model;
    
    //We can only do manual types.
    if($this->raw_data()->type->get() !== 'manual')
      throw new \exception\Exception('Package type other than manual has not been implemented yet.');
    
    //Empty model, used for checks later on.
    $model = Data();
    
    //Reference this instance.
    $that = $this;
    
    //See if we have a reference for this package.
    $reference_file = $this->directory().DS.'.package'.DS.'reference-id';
    $reference_support = static::reference_support();
    $reference = 'NULL';
    if(file_exists($reference_file) && $reference_support)
    {
      
      $reference = file_get_contents($reference_file);
      try{
        $model = mk('Sql')
          ->table('update', 'Packages')
          ->where('reference_id', "'$reference'")
          ->execute_single()
          ->is('empty', function()use($self, $reference_file){
            mk('Logging')->log('ManualPackage', 'Referencing', 'Invalid reference found for '.$self->raw_data()->title.', deleting.');
            unlink($reference_file);
          });
      }
      
      //If this broke, we don't have this reference_id field yet or the update component is not installed.
      catch(\exception\Sql $ex){
        mk('Logging')->log('ManualPackage', 'Referencing', 'Unable to query reference-id. '.$ex->getMessage());
      }
      
    }
    
    //Perhaps we have a chance to create a reference now.
    if(!$reference_support || !file_exists($reference_file))
    {
      
      //Get the package from the database.
      //Use a try catch in case we're installing the update package and the tables don't exist.
      try{
        $raw_data = $this->raw_data();
        $model = mk('Sql')
          ->table('update', 'Packages')
          ->where('title', "'".$this->raw_data()->title."'")
          ->execute_single()
          ->is('empty', function()use($raw_data){
            if($raw_data->old_title->is_set())
              return mk('Sql')
                ->table('update', 'Packages')
                ->where('title', "'".$raw_data->old_title."'")
                ->execute_single();
          });
      }
      
      //In case of a Sql exception we are self-installing.
      //Return an empty data object.
      catch(\exception\Sql $ex){
        //Create an empty placeholder.
        mk('Logging')->log('Update', 'Query error', 'Seems like a self-install. '.$ex->getMessage());
        return Data();
      }
      
      if($reference_support){
        try
        {
          
          //Create a unique reference key.
          do {
            
            $reference = mk('Security')->random_string(40);
            
            $matches = mk('Sql')
              ->table('update', 'Packages')
              ->where('reference_id', "'$reference'")
              ->count();
            
          } while($matches->get('int') > 0);
          
          //Update the package with this reference key.
          if(!$model->is_empty())
          {
            
            $model->merge(array(
              'reference_id' => $reference
            ))->save();
            
          }
          
          //Save the reference to the file.
          file_put_contents($reference_file, $reference);
          
        }
        
        //An exception here means that references aren't yet supported by the installed update component.
        catch(\exception\Sql $ex){
          mk('Logging')->log('ManualPackage', 'Referencing', 'Unable to create new reference-id. '.$ex->getMessage());
        }
      }
      
    }
    
    //Don't cache and return a new model if the package was not in the database.
    if($model->is_empty()){
      
      return mk('Sql')->model('update', 'Packages')->set(array(
        'title' => $this->raw_data()->title,
        'description' => $this->raw_data()->description,
        'type' => self::TYPE_ID,
        'reference_id' => $reference
      ));
      
    }
    
    $this->model = $model;
    return $model;
    
  }
  
}