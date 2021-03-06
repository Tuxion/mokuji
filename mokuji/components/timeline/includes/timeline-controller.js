(function($, exports){
  
  var hasFeedback = (window.app && app.Feedback);
  
  //Create a new page type controller called TimelineController.
  var TimelineController = PageType.sub({
    
    //Is this a dirty timeline preview?
    dirty: false,
    
    //Please keep on the same page.
    entriesPage: 1,
    
    //Cache timelines and filters.
    timelines: {},
    filters: {},
    
    //Force a language?
    force_language: false,
    
    //Define the tabs to be created.
    tabs: {
      'Entries': 'entriesTab',
      'Composition': 'compositionTab'
    },
    
    //Define the elements for jsFramework to keep track of.
    elements: {
      'title': '#timeline-title-form .title',
      'titleForm': '#timeline-title-form',
      'compositionForm': '#timeline-composition-form',
      'compositionFormInput': '#timeline-composition-form :input',
      'timelinePreview': '#timeline-preview',
      'entryPagination': '.entry-pagination',
      'btn_entries_page': '.entry-pagination .page',
      'paginationWrapper': '.pagination-wrapper',
      'editingPage': '.pagination-wrapper .page-2',
      'btn_edit_item': '.edit-item',
      'btn_delete_item': '.delete-item',
      'btn_entry_cancel': '#timeline-entry-form .cancel',
      'sel_force_language': '#timeline-composition-form select[name=force_language]'
    },
    
    events: {
      
      'click on btn_entries_page': function(e){
        e.preventDefault();
        this.loadEntries($(e.target).attr('data-page'));
      },
      
      'click on btn_edit_item': function(e){
        e.preventDefault();
        this.editEntry($(e.target).attr('data-entry'));
      },
      
      'click on btn_delete_item': function(e){
        e.preventDefault();
        this.deleteEntry($(e.target).attr('data-entry'));
      },
      
      'click on btn_entry_cancel': function(e){
        e.preventDefault();
        this.returnToPosts();
      },
      
      'change on compositionFormInput': function(e){
        this.dirty = true;
        this.filters = this.compositionForm.formToObject();
      },
      
      'change on sel_force_language': function(e){
        var value = $(e.target).val();
        this.force_language = value > 0 ? value : false;
        this.editingPage.find(':input[name=force_language]').val(value);
        this.title.trigger('blur');
      },
      
      //Let findability know we have a recommended default.
      'keyup on title': function(e){
        app.Page.Tabs.findabilityTab.recommendTitle(
          $(e.target).val(),
          this.force_language ?
            'ALL':
            $(e.target).closest('.multilingual-section').attr('data-language-id')
        );
      }
      
    },
    
    //Return to posts.
    returnToPosts: function(){
      
      var self = this;
      
      self.paginationWrapper.animate({left:'0%'}, 300, function(){
        self.editingPage.empty();
        self.refreshElements();
      });
      
    },
    
    deleteEntry: function(id){
      
      var self = this;
      
      if(window.confirm('This entry will be deleted from every timeline. Are you sure?')){
        
        if(hasFeedback) app.Feedback.working('Deleting entry.');
        $.rest('DELETE', '?rest=timeline/entry/'+id).done(function(data){
          self.loadEntries();
          app.Feedback.success('Deleting entry succeeded.');
        }).error(function(){
          app.Feedback.error('Deleting entry failed.');
        });
        
      }
      
    },
    
    //Edit entry.
    editEntry: function(id){
      
      var self = this;
      
      self.paginationWrapper.animate({left:'-100%'}, 300);
      
      self.editingPage.html('<p class="loading">Loading timeline entry...</p>');
      
      $.rest('GET', '?rest=timeline/entry/'+id).done(function(data){
        
        self.editingPage.empty();
        
        var form = self.definition.templates.entryEdit.tmpl({
          data: data,
          page_id: self.page,
          timelines: self.timelines,
          force_timeline: self.filters && self.filters.timeline_id ? self.filters.timeline_id : false,
          force_language: self.force_language,
          languages: app.Page.Languages.data.languages
        }).appendTo(self.editingPage);
        
        var imageId = form.find('[name=thumbnail_image_id]')
          , entryImage = form.find('.entry_image')
          , deleteImage = form.find('.delete-entry-image');
        
        //Reload plupload, if present.
        if($.fn.txMediaImageUploader)
        {
          
          form.find('.image_upload_holder').txMediaImageUploader({
            singleFile: true,
            callbacks: {
              
              serverFileIdReport: function(up, ids, file_id){
                
                imageId.val(file_id);
                
                $.rest('GET', '?rest=media/generate_url/'+file_id, {filters:{fit_height:200, fit_weight:300}})
                  .done(function(result){
                    entryImage.attr('src', result.url).show();
                    deleteImage.show();
                  });
                
              }
              
            }
          });
          
          //Allow image deletion.
          form.on('click', '.delete-entry-image', function(e){
            e.preventDefault();
            $.rest('DELETE', '?rest=media/image/'+imageId.val())
              .done(function(){
                imageId.val('');
                entryImage.attr('src', '').hide();
                deleteImage.hide();
              });
            
          });
          
        }
        
        //If not there, hide the div that holds the uploader normally.
        else{
          form.find('.image_upload_holder').hide();
        }
        
        form.restForm({
          beforeSubmit: function(){
            if(hasFeedback) app.Feedback.working('Saving entry...').startBuffer();
          },
          success: function(entry){
            self.loadEntries();
            self.returnToPosts();
            if(hasFeedback) app.Feedback.success('Saving entry succeeded.').stopBuffer();
          },
          error: function(){
            if(hasFeedback) app.Feedback.error('Saving entry failed.').stopBuffer();
          }
        });
        
        //Create unique id for the text editors.
        form.find('textarea.editor').each(function(){
          var $that = $(this);
          $that.attr('id', $that.attr('id')+Math.floor((Math.random()*100000)+1));
          tx_editor.init({selector:'#'+$that.attr('id')});
        });
        
        //Refresh elements.
        self.refreshElements();
        
        //Make sure the first tab (which has the previews) applies multilingual clauses.
        app.Page.Tabs.state.controllers[0].setMultilanguageSection(
          self.force_language > 0 ? self.force_language :
          app.Page.Languages.currentLanguageData().id
        );
        
      });
      
    },
    
    //Loads entries for this page.
    loadEntries: function(page){
      
      var self = this;
      
      //Since we're refreshing, remove dirty flag.
      self.dirty = false;
      
      //Pages start at 1.
      if(page <= 0)
        page = 1;
      
      //Load the page we're on.
      if(!page)
        page = self.entriesPage;
      
      //Store the page we specified.
      else
        self.entriesPage = page;
      
      self.timelinePreview.html('<p class="loading">Loading...</p>');
      self.entryPagination.empty();
      
      //Load a page of entries. (Note: don't hide the future for admins)
      $.rest('GET', '?rest=timeline/entries/'+page, $.extend({}, self.filters, {is_future_hidden: 0, is_past_hidden: 0}))
      
      //When we got them.
      .done(function(result){
        
        //If we ended up with less pages than the page we requested. Get the last page.
        if(result.pages > 0 && result.pages < page)
          return self.loadEntries(result.pages);
        
        self.timelinePreview.empty();
        
        //Insert pagination.
        var page_numbers = {};
        for(var i = 1; i <= result.pages; i++) page_numbers[i] = i;
        self.entryPagination.html(self.definition.templates.entryPagination.tmpl({
          page: parseInt(result.page, 10),
          pages: parseInt(result.pages, 10),
          page_numbers: page_numbers
        }));
        
        var hasEntries = false;
        
        if(result.entries) $.each(result.entries, function(i){
          
          hasEntries = true;
          
          self.templateEntry(this)
            .appendTo(self.timelinePreview);
          
        });
        
        if(!hasEntries){
          self.timelinePreview.html('<p class="no-entries">There are no entries yet.</p>');
        }
        
        //Refresh elements.
        self.refreshElements();
        
        //Make sure the first tab (which has the previews) applies multilingual clauses.
        app.Page.Tabs.state.controllers[0].setMultilanguageSection(
          self.force_language > 0 ? self.force_language :
          app.Page.Languages.currentLanguageData().id
        );
        
      })
      
      .error(function(){
        self.timelinePreview.html('<p class="error">Could not load preview.</p>');
      });
      
    },
    
    //Templates one entry based on entry data.
    templateEntry: function(data){
      
      //Template the entry template.
      return this.definition.templates.entry.tmpl({
        data: data,
        force_language: self.force_language,
        languages: app.Page.Languages.data.languages
      });
      
    },
    
    //Refresh the composition form.
    refreshComposition: function(data){
      
      //Template the composition template and replace HTML.
      this.compositionForm.replaceWith(
        this.definition.templates.compositionTab.tmpl({
          data: data,
          languages: app.Page.Languages.data.languages
        })
      );
      
      this.refreshElements();
      this.bindCompositionForm();
      this.title.trigger('blur');
      
    },
    
    //Bind composition form.
    bindCompositionForm: function(){
      
      var self = this;
      
      self.compositionForm.restForm({
        beforeSubmit: function(data){
          $.extend(true, data, self.titleForm.formToObject());
        },
        success: function(data){
          self.filters = data.page;
          self.refreshComposition(data);
          if(hasFeedback) app.Feedback.success('Saving timeline composition succeeded.');
        },
        error: function(){
          if(hasFeedback) app.Feedback.error('Saving timeline composition failed.');
        }
      });
      
    },
    
    //Retrieve input data (from the server probably).
    getData: function(pageId){
      
      var self = this
        , D = $.Deferred()
        , P = D.promise();
      
      //Retrieve input data from the server based on the page ID
      $.rest('GET', '?rest=timeline/page/'+pageId, {})
      
      //In case of success, this is no longer fresh.
      .done(function(d){
        self.page = d.page.page_id;
        self.timelines = d.timelines;
        self.force_language = d.page.force_language ? d.page.force_language : false,
        D.resolve(d);
      })
      
      //In case of failure, provide default data.
      .fail(function(){
        D.resolve({
          page_id: pageId
        });
      });
      
      return P;
      
    },
    
    //When rendering of the tab templates has been done, do some final things.
    afterRender: function(){
      
      var self = this;
      
      //Load the filters used.
      self.filters = self.compositionForm.formToObject();
      
      //Make composition a REST form.
      this.bindCompositionForm();
      
      //Bind title form once.
      self.titleForm.submit(function(e){
        e.preventDefault();
        self.compositionForm.trigger('submit');
      });
      
      //When switching tabs, see if we need to reload entries.
      app.Page.Tabs.subscribe('tabChanged', function(e, tab){
        
        //Reload if we need to.
        if(tab.title === 'Entries'){
          
          //Fresh diapers are applied here.
          if(self.dirty)
            self.loadEntries();
          
          //Force disable language tabs if it's set.
          app.Page.setMultilingual(self.force_language === false);
          
          //Make sure the first tab (which has the previews) applies multilingual clauses.
          app.Page.Tabs.state.controllers[0].setMultilanguageSection(
            self.force_language > 0 ? self.force_language :
            app.Page.Languages.currentLanguageData().id
          );
          
        }
        
      });
      
      //Force language please.
      app.Page.setMultilingual(self.force_language === false);
      
      //Load preview entries.
      self.loadEntries();
      
    },
    
    //Saves the data currently present in the different tabs controlled by this controller.
    save: function(e, pageId){
      
      //Save the filters (which chains into titles).
      this.compositionForm.trigger('submit');
      
    },
    
    afterSave: function(data){
    }
    
  });

  //Export the namespaced class.
  TimelineController.exportTo(exports, 'cmsBackend.timeline.TimelineController');
  
})(jQuery, window);
