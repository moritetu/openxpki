========================
WebUI Page API Reference
========================

The web pages are created (mainly) on the client from a JSON control stucture delivered by the server. This document describes the structure expected by the rendering engine.

Top-Level Structure
====================

This is the root element of any json result::

    %structure = (

        page => { TOP_LEVEL_INFO},
        
        right => [ PAGE_SECTION, PAGE_SECTION,...] , # optional, information which will be displayed in additional right pane
        
        main => [ PAGE_SECTION, PAGE_SECTION,...] , # information which will be displayed in the main section
        
        reloadTree => BOOL (1/0), # optional, the browser will perform a complete reload. If an additional "goto" is set, the page-url will change to this target
        
        goto => STRING PAGE, # optional, will be evaluated as url-hashtag target                 
        
        status => { STATUS_INFO } # optional
    );

    Example { reloadTree => 1, goto => 'login/login'}


Page Head (TOP_LEVEL_INFO):
--------------------------------

This is rendered as the page main headline and intro text.
::

    TOP_LEVEL_INFO:
    {
        label => STRING, #Page Header
        description => STRING, # additional text (opt.)
    }
            
    Example: page => {label => 'OpenXPKI Login', description => 'Please log in!'}


Status Notification (STATUS_INFO):
---------------------------------------

Show a status bar on top of the page, the level indicates the severity and results in different colors of the status bar.
::

    STATUS_INFO:
    { 
        level => STRING, # allowed values: "info", "success","warn", "error"
        message => STRING # status message shown
    }
       
    Example:   status => { level => 'error', message => 'Login credentials are wrong!' } 


Page Level
==========

The page sections (``main`` and ``right``) can hold multiple subpage definitions. The main section must always contain at least one section while right can be omitted or empty.
      
Page Section (PAGE_SECTION)
--------------------------------

This is the top level container of each page section.
::

    PAGE_SECTION:
    {
        type => STRING # determines type of section, can be one of: text|grid|form|keyvalue
            
        content => {
            label => STRING # optional, section headline
            
            description => STRING , # optional, additional text (html is allowed)
                    
            buttons => [BUTTON_DEF, BUTTON_DEF, BUTTON_DEF] , #optional, defines the buttons/links for this section
                    
            # additional content-params depending on type (see below)
        },
                            
        # additional section-params depending on type:
    }


SECTION-TYPE "text"
-------------------

Print the label as subheadline (h2) and description as intro text, buttons are rendered after the text. Does not have any additional parameters. Note: If you omit label and description this can be used to render a plain button bar or even a single button.

SECTION-TYPE "grid"
-------------------

Grids are rendered using the `jquery datatable plugin (http://datatables.net) <http://datatables.net>`_. The grid related parameters are just pushed to the dataTables engine and therefore have a different notation and syntax used as the remainder of the project.
::

    content => {
        label => .., description => .., buttons => ..,
        columns => [ GRID_COL_DEF, GRID_COL_DEF , GRID_COL_DEF... ],
        data => [ GRID_ROW, GRID_ROW, GRID_ROW, ... ],
        actions => [ GRID_ACTION_DEF, GRID_ACTION_DEF, GRID_ACTION_DEF... ], # defines available actions, displayed as context menu
        processing_type => STRING, # only possible value (for now) is "all" 
    }

    GRID_COL_DEF:
    {
        sTitle => STRING, # displayed title of that columnd AND unique key
        format => STRING_FORMAT # optional, triggers a formatting helper (see below)
    }

    GRID_ROW:
        ['col1','col2','col3']


    GRID_ACTION_DEF:
    {
        path => STRING_PATH, # will be submitted to server as page. terms enclosed in {brackets} will be evaluated as column-keys and replaced with the value of the given row for that column
        label => STRING, # visible menu entry
        target => STRING_TARGET # optional, where to open the new page, one of main|right|modal|tab
        icon => STRING , # optional, file name of image icon, must be placed in htdocs/img/contextmenu
    }
        

Columns, whose sTitle begin with an underscore will not be displayed but used as internal information (e.g. as path in GRID_ACTION_DEF). A column with the special title ``_status`` is used as css class for the row. Also a pulldown menu to filter by status will be displayed. 
The rows hold the data in form of a positional array.

Action target ``modal`` creates a modal popup, ``tab`` inits or extends a tabbed window view in the current section.

*Example*::

    content => {
        columns => [
	    { sTitle => "Serial" },	
            { sTitle => "Subject" },                                                
	    { sTitle => "date_issued", format => 'timestamp'},
	    { sTitle => "link", format => 'link'},
	    { sTitle => "_id"}, # internal ID (will not be displayed)
	    { sTitle => "_status"}, # row status 
        ],
        data => [
            ['0123','CN=John M Miller,DC=My Company,DC=com',1379587708, {page => 'http://../', label => 'Click On Me'}, 'swBdX','issued'],
            ['0456','CN=Bob Builder,DC=My Company,DC=com',1379587517,{...},'qqA2H','expired'],
        ],
        actions => [
            { 
                path => 'cert!detail!{_id}',
                label => 'Details',
                icon => 'view',
                target => 'modal'
            },
            {
                path => 'cert!mail2issuer!{email}',
                label => 'Send an email to issuer'
            },
        ]
    }
            
SECTION-TYPE "form"
-------------------

Render a form to submit data to the server
::

    content => {
        label => .., description => .., 
        buttons => [ ... ], # a form must contain at least one button to be useful
        fields => [ FORM_FIELD_DEF,FORM_FIELD_DEF,FORM_FIELD_DEF ],
    }
    
    FORM_FIELD_DEF:
    {
        name => STRING # internal key - will be transmitted to server
        value => MIXED, # value of the field, scalar or array (depending on type)
        label => STRING, # displayed label
        type => STRING_FIELD_TYPE, # see below for supported field types 
        is_optional => BOOL, # if false (or not given at all) the field is required
        clonable => BOOL,  creates fields that can be added more than once
        # + additional keys depending for some types
    }


Field-Type "text", "hidden", "password", "textarea"
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

No additional parameters, create a simple html form element without any extras.

Field-Type "checkbox/bool"
^^^^^^^^^^^^^^^^^^^^^^^^^^

A html checkbox, ``value`` and ``is_optional`` are without effect, as always 0 or 1 is send to the server.

Field-Type "date"
^^^^^^^^^^^^^^^^^^ 

A text field with a jquery datapicker attached. Additional (all optional) params are:
::

    FORM_FIELD_DEF:
    {
        notbefore => INTEGER, # optional, unixtime, earliest selectable date
        notafter => INTEGER, # optional, unixtime, earliest selectable date 
        return_format => STRING # one of terse|printable|iso8601|epoch, see OpenXPKI::Datetime
    }
    
Field-Type "select"
^^^^^^^^^^^^^^^^^^^^ 

A html select element, the options parameter is requried, others are optional::

    FORM_FIELD_DEF:
    {
        options => [{value=>'key 1',label=>'Label 1'},{value=>'key 2',label=>'Label 2'},...],
        prompt => STRING # first option shown in the box, no value (soemthing like "please choose")
        editable => BOOL # activates the ComboBox
    }

The ``options`` parameter can be fetched via an ajax call. If you set ``options => 'fetch_cert_status_options'``, an ajax call to "server_url.cgi?action=fetch_cert_status_options" is made. The call must return the label/value list as defined given above.

Setting the editable flag to a true value enables the users to enter any value into the select box (created with `Bootstrap Combobox <https://github.com/danielfarrell/bootstrap-combobox>`_).

Field-Type "radio"
^^^^^^^^^^^^^^^^^^

The radio type is the little brother of the select field, but renders the items as a list of items using html radio-buttons. It shares the syntax of the ``options`` field with the select element::

    FORM_FIELD_DEF:
    {
        options => [{....}] or 'ajax_action_string'..
        multi => BOOL, # optional, if true, uses checkbox elements instead radio buttons
    }
       

Field-Type "upload"
^^^^^^^^^^^^^^^^^^^

Renders a field to upload files with some additional benefits::

    FORM_FIELD_DEF:
    {
        mode => STRING, # one of hidden, visible, raw
        allowedFiles => ARRAY OF STRING, # ['txt', 'jpg'], 
        textAreaSize => {width => '10', height => '15'},
    }

By default, a file upload button is shown which loads the selected file into a hidden textarea. Binary content is encoded with base64 and prefixed with the word "binary:". With `mode = visible` the textarea is also shown so the user can either upload or paste the data (which is very handy for CSR uploads), the textAreaSize will affect the size of the area field. With ``mode = raw`` the element degrades to a html form upload button and the selected file is send with the form as raw data.

AllowedFiles can contain a list of allowed file extensions. 

Item Level
==========
     
Buttons (BUTTON_DEF)
--------------------

Defines a button.::

    {
        page => STRING_PAGE,
        action => STRING_ACTION, # parameters "page" and "action" will be transmitted to server. if an "action" is given, POST will be used instead of GET 
        label => STRING, # The label of the button
        target => STRING_TARGET, # one of main|modal|right|tab (optional, default is main)
        css_class => STRING, # optional, css class for the button element
        do_submit => BOOL, # optional, if true, the button submits the contents of the form to the given page/action target, only available with form-section
    }

                  
Formattet Strings (STRING_FORMAT)
---------------------------------

Tells the ui to process the data before rendering with a special formatter. Available methods are:

timestamp
^^^^^^^^^

Expects a unix timestamp and outputs a readable date.

certstatus
^^^^^^^^^^

Colorizes the given status word using css tags, e.g. ``issued`` becomes::

    <span class="certstatus-issued">issued</span>

link
^^^^

Create an internal framework link to a page or action, expects a hash with a ``label`` and either ``page`` or ``action``.


Customization
=============

The framework allows to register additional components via an exposed api.

Form-Field
-----------

Add a new FormField-Type::

    OXI.FormFieldFactory.registerComponent('type','ComponentName',JS_CODE [,bOverwriteExisting]);
        
*Example*::

    OXI.FormFieldFactory.registerComponent('select','MySpecialSelect', OXI.FormFieldContainer.extend({
        ....
    }), true);

This will overwrite the handler for the select element. The ComponentName will be registered in the OXI Namespace and can be used to call the object from within userdefined code. 


Formatter
---------

Add a new Format-Handler::

    OXI.FormatHelperFactory.registerComponent('format','ComponentName',JS_CODE [,bOverwriteExisting])
        


