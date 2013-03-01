<?php namespace components\cms; if(!defined('TX')) die('No direct access.');

class EntryPoint extends \dependencies\BaseEntryPoint
{

  public function entrance()
  {
    
    //When loading PageType templates
    if(tx('Data')->get->pagetypetemplate->is_set()){
      
      $parts = explode('/', tx('Data')->get->pagetypetemplate->get());
      $com = array_shift($parts);
      $pagetype = array_shift($parts);
      $tmpl = implode('/', $parts);
      
      $path = PATH_COMPONENTS.DS.$com.DS.'pagetypes'.DS.$pagetype.DS.$tmpl;
      
      return load_html($path, array(
        'component' => $com,
        'pagetype' => $pagetype
      ));
      
    }
    
    //Backend
    if(tx('Config')->system()->check('backend'))
    {
      
      //Display a login page?
      if(!tx('Account')->user->check('login'))
      {
        
        //Redirect to custom login page is available.
        if(url('')->segments->path == '/admin/' && tx('Config')->user()->login_page->not('empty')->get('bool')){
          header("Location: ".url(URL_BASE.tx('Config')->user()->login_page));
        }

        //Otherwise: show awesome login screen.
        return $this->template('tx_login', 'tx_login', array(), array(
          'content' => tx('Component')->sections('account')->get_html('login_form')
        ));

      }
      
      //Set site_id filter.
      tx('Data')->get->goto_site_id->is('set')
        ->success(function($gtsid){
          tx('Data')->session->cms->filters->site_id = $gtsid->get();
        })
        ->failure(function(){
          tx('Data')->session->cms->filters->site_id = tx('Site')->id;
        });
      
      return $this->template('cms_backend', 'cms_backend', array(
        'plugins' =>  array(
          load_plugin('jquery'),
          load_plugin('jquery_ui'),
          load_plugin('jquery_rest'),
          load_plugin('jquery_comboselect'),
          load_plugin('jquery_postpone'),
          load_plugin('nestedsortable'),
          load_plugin('ckeditor'),
          load_plugin('elfinder'),
          load_plugin('jquery_tmpl'),
          load_plugin('jsFramework'),
          load_plugin('underscore'),
          load_plugin('idtabs3')
        ),
        'scripts' => array(
          'cms_backend' => '<script type="text/javascript" src="'.URL_COMPONENTS.'cms/includes/backend.js"></script>',
          'cms_backend_pagetype' => '<script type="text/javascript" src="'.URL_COMPONENTS.'cms/includes/PageType.js"></script>'
        )
      ),
      array(
        'content' => $this->view('app', tx('Data')->get->view->get())
      ));


    }
    
    //Frontend
    else
    {

      $that = $this;
      
      //If we need to claim our account, do that now before anything else.
      if(tx('Component')->helpers('account')->call('should_claim')){
        
        $template_id = tx('Config')->user('template_id')->otherwise(1)->get('int');
        $template = tx('Sql')->table('cms', 'Templates')->pk($template_id)->execute_single();
        
        $theme_id = tx('Config')->user('theme_id')->otherwise(1)->get('int');
        $theme = tx('Sql')->table('cms', 'Themes')->pk($theme_id)->execute_single();
        
        return $that->template($template->name, $theme->name, array(
          'title' => __('cms', 'Claim your account', true),
          'plugins' =>  array(
            load_plugin('jquery'),
            load_plugin('jquery_rest'),
            load_plugin('jquery_postpone')
          ),
        ),
        array(
          'content' => tx('Component')->views('account')->get_html('claim_account')
        )); //$that->template();
        
      }

      tx('Validating get variables', function()use($that){
        
        //validate page id
        tx('Data')->get->pid->not('set', function(){

          tx('Config')->user('homepage')->is('empty', function(){
            throw new \exception\NotFound('No homepage was set.');
          })->failure(function(){
            tx('Url')->redirect(tx('Config')->user('homepage'), true);
          });

        })->validate('Page ID', array('number'=>'integer', 'gt'=>0));
        
        //check if page id is present in database
        $page = tx('Sql')
          ->table('cms', 'Pages')
          ->pk(tx('Data')->get->pid)
          ->execute_single()
          ->is('empty', function(){
            throw new \exception\EmptyResult('The page ID does not refer to an existing page.');
          });
        
        //Check user permissions.
        tx('Component')->helpers('cms')->page_authorisation($page->id);
        
        //validate module id
        tx('Data')->get->mid->is('set', function($mid){
          $mid->validate('Module ID', array('number'=>'integer', 'gt'=>0));
        });

      })

      ->failure(function(){

        //first see if we can go back to where we came from
        $prev = tx('Url')->previous(false, false);
        if($prev !== false && !$prev->compare(tx('Url')->url)){
          tx('Url')->redirect(url($prev, true));
          return;
        }

        tx('Config')->user('homepage')->is('set', function($homepage){

          $redirect = url($homepage);

          $redirect->data->pid->is('set')->and_is(function($pid){
            return tx('Sql')
              ->table('cms', 'Pages')
              ->pk($pid)
              ->execute_single()
              ->is_set();
          })
          ->success(function()use($redirect){tx('Url')->redirect($redirect);})
          ->failure(function(){tx('Url')->redirect('/admin/');});

        });

      })

      ->success(function()use($that, &$output){
        
        //load a layout-part
        if(tx('Data')->get->part->is_set()){
          $output = $that->section('page_part');
        }
        
        //or are we going to load an entire page?
        elseif(tx('Data')->get->pid->is_set()){
          
          $pi = $that->helper('get_page_info', tx('Data')->get->pid);
          $lpi = $pi->info->{tx('Language')->get_language_id()};
          
          //See if the URL key is correct.
          $url_key = $lpi->url_key;
          $pretty_url = URL_BASE."{$pi->id}/{$url_key}";
          if($url_key->is_set() && $url_key->get() != tx('Data')->get->pkey->get()){
            header('Location: '.$pretty_url);
            return;
          }
          
          
          /* ------- Set all the headers! ------- */
          
          //TODO: improve some of the default site-wide settings
          //TODO: thumbnail images for twitter/facebook
          //TODO: author en (publish tijden?) voor facebook
          
          $site_name = tx('Config')->user('site_name')->otherwise('My Tuxion CMS Website');
          $site_twitter = tx('Config')->user('site_twitter');
          $site_googleplus = tx('Config')->user('site_googleplus');
          $site_author = tx('Config')->user('site_author');
          $site_description = tx('Config')->user('site_description')->otherwise('My Tuxion CMS Website');
          $site_keywords = tx('Config')->user('site_keywords')->otherwise('Tuxion, CMS');
          $title = $lpi->title->otherwise($pi->title)->get();
          $title .= ($title ? ' - ' : '') . $site_name;
          $description = $lpi->description->otherwise($site_description)->get();
          $keywords = $lpi->keywords->otherwise($site_keywords)->get();
          
          tx('Ob')->meta('Page Headers');?>
            
            <!-- Standard HTML SEO -->
            <meta http-equiv="content-language" content="<?php echo tx('Language')->get_language_code(); ?>" />
            <meta name="description" content="<?php echo $description; ?>" />
            <meta name="keywords" content="<?php echo $keywords; ?>" />
            <meta name="author" content="<?php echo $lpi->author->otherwise($site_author); ?>" />
            
            <!-- Open Graph (Facebook) -->
            <meta property="og:url" content="<?php echo $pretty_url; ?>" />
            <meta property="og:type" content="website" />
            <meta property="og:article:tag" content="<?php echo $lpi->og_keywords->otherwise($keywords); ?>" />
            <meta property="og:locale" content="<?php echo tx('Language')->get_language_code(); ?>" />
            <meta property="og:title" content="<?php echo $lpi->og_title->otherwise($title); ?>" />
            <meta property="og:description" content="<?php echo $lpi->og_description->otherwise($description); ?>" />
            <meta property="og:site_name" content="<?php echo $site_name; ?>" />
            
            <!-- Twitter Cards -->
            <meta name="twitter:card" content="summary" />
            <meta name="twitter:title" content="<?php echo $lpi->tw_title->otherwise($title); ?>" />
            <meta name="twitter:description" content="<?php echo $lpi->tw_description->otherwise($description); ?>" />
            <meta name="twitter:url" content="<?php echo $pretty_url; ?>" />
            <meta name="twitter:site" content="<?php echo $site_twitter; ?>" />
            <meta name="twitter:creator" content="<?php echo $lpi->tw_author->otherwise($site_twitter); ?>" />
            
            <!-- Google+ Authorship -->
            <link rel="author" href="<?php echo $lpi->gp_author->otherwise($site_googleplus); ?>" />
            
          <?php tx('Ob')->end();
          
          /* ------- END - headers ------- */
          
          $output = $that->template($pi->template, $pi->theme, array(
            'title' => $title,
            'plugins' =>  array(
              load_plugin('jquery')
              // load_plugin('jquery_ui'),
              // load_plugin('nestedsortable'),
              // load_plugin('jsFramework')
            ),
          ),
          array(
            'admin_toolbar' => $that->section('admin_toolbar'),
            'content' => $that->view('page')
          ));

        }

        else{
          throw new \exception\Unexpected('Failed to detect what to load. :(');
        }

      });

      return $output;

    }

  }

}
