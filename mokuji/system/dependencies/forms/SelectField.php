<?php namespace dependencies\forms; if(!defined('TX')) die('No direct access.');

use \dependencies\BaseModel;

class SelectField extends BaseFormField
{
  
  protected
    $option_set;
  
  /**
   * Initiates a new form field.
   *
   * @param string $column_name The table column name for this field.
   * @param string $title The preferred and translated title to use for this field.
   * @param BaseModel $model The model that this field is related to.
   * @param array $options An optional set of options to further customize this field.
   */
  public function __construct($column_name, $title, BaseModel $model, array $options=array())
  {
    
    parent::__construct($column_name, $title, $model, $options);
    
    
    ### Determine the option set. ###
    
    //If it's given in the overrides.
    if(isset($options['options']))
    {
      
      //Pretty easy.
      $this->option_set = $options['options'];
      
    }
    
    //Otherwise, find out from the database.
    else
    {
      
      $field = $model->table_data()->fields[$column_name];
      
      //The enum implementation.
      if($field->type->get() === 'enum')
      {
        
        //Also pretty easy.
        $this->option_set = array_combine($field->arguments->as_array(), $field->arguments->as_array());
        
      }
      
      if(!isset($this->option_set))
        throw new \exception\Programmer('SelectField has no source for the option set');
      
    }
    
    ### End - determine option set. ###
    
  }
  
  /**
   * Outputs this field to the output stream.
   * 
   * @param array $options An optional set of options to further customize the rendering of this field.
   */
  public function render(array $options=array())
  {
    
    parent::render($options);
    
    $value = $this->insert_value ? $this->value->get() : '';
    
    $uid = 'l_'.tx('Security')->random_string();
    
    ?>
    <div class="ctrlHolder for_<?php echo $this->column_name; ?>">
      
      <label for="<?php echo $uid; ?>" class="label">
        <?php __($this->model->component(), $this->title); ?>
      </label>
      
      <select id="<?php echo $uid; ?>" name="<?php echo $this->column_name; ?>">
        <option value=""></option>
        <?php foreach($this->option_set as $key=>$title): ?>
          <option value="<?php echo $key; ?>" <?php if($value == $key) echo 'selected="selected"' ?>>
            <?php __($this->model->component(), $title); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php
    
  }
  
}
