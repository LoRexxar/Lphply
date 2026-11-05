<?php
/**
 * @package Unlimited Elements
 * @author unlimited-elements.com
 * @copyright (C) 2021 Unlimited Elements, All Rights Reserved.
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 * */
if ( ! defined( 'ABSPATH' ) ) exit;

class UniteCreatorOutputWork extends HtmlOutputBaseUC{

	private static $serial = 0;

	const SELECTOR_VALUE_PLACEHOLDER = "{{value}}";

	const TEMPLATE_HTML = "html";
	const TEMPLATE_CSS = "css";
	const TEMPLATE_CSS_ITEM = "css_item";
	const TEMPLATE_JS = "js";
	const TEMPLATE_HTML_ITEM = "item";
	const TEMPLATE_HTML_ITEM2 = "item2";

	private $addon;
	private $isInited = false;
	private $objTemplate;
	private $isItemsExists = false;
	private $itemsType = null;
	private $paramsCache = null;
	private $cacheConstants = null;
	private $processType = null;
	private $generatedID = null;
	private $systemOutputID = null;
	private $isModePreview = false;
	private $arrOptions;

	private $isShowDebugData = false;
	private $debugDataType = "";
	private $valuesForDebug = null;
	
	private $itemsSource = "";
	
	private $isGutenberg = false;
	private $isBackground = false;
	private $isGutenbergBackground = false;
	private static $isGutenbergGlobalCssAdded = false;
	
	private static $arrScriptsHandles = array();

	private static $arrUrlCacheCss = array();
	private static $arrHandleCacheCss = array();

	private static $arrUrlCacheJs = array();
	private static $arrHandleCacheJs = array();

	public static $isBufferingCssActive = false;
	public static $bufferBodyCss;
	public static $bufferCssIncludes;
	
	private static $arrGeneratedIDs = array();
		
	private $htmlDebug = "";

    private $processedItemsData = false;


	/**
	 * construct
	 */
	public function __construct(){
		
		$this->addon = new UniteCreatorAddon();

		if(GlobalsUC::$isProVersion)
			$this->objTemplate = new UniteCreatorTemplateEnginePro();
		else
			$this->objTemplate = new UniteCreatorTemplateEngine();

		$this->processType = UniteCreatorParamsProcessor::PROCESS_TYPE_OUTPUT;
		
		if(GlobalsProviderUC::$renderPlatform == GlobalsProviderUC::RENDER_PLATFORM_GUTENBERG)
			$this->isGutenberg = true;
				
	}


	/**
	* set output type
	 */
	public function setProcessType($type){

		UniteCreatorParamsProcessor::validateProcessType($type);

		$this->processType = $type;

	}
	
	
	/**
	 * validate inited
	 */
	private function validateInited(){
		if($this->isInited == false)
			UniteFunctionsUC::throwError("Output error: addon not inited");

	}

	private function a_________INCLUDES_______(){}

	/**
	 * clear includes cache, avoid double render bug
	 */
	public static function clearIncludesCache(){
		
		// define handles we want to keep
	    $preserveHandles = array(
	        "font-awesome",
	        "font-awesome-4-shim"
	    );
	
	    $keepHandles = array();
	
	    // save flags before clearing
	    foreach ($preserveHandles as $handle) {
	        if (isset(self::$arrHandleCacheCss[$handle])) {
	            $keepHandles[$handle] = true;
	        }
	    }
	
	    // reset caches
	    self::$arrHandleCacheCss = array();
	    self::$arrHandleCacheJs  = array();
	    self::$arrUrlCacheCss    = array();
	    self::$arrUrlCacheJs     = array();
	
	    // restore preserved handles
	    foreach ($keepHandles as $handle => $val) {
	        self::$arrHandleCacheCss[$handle] = $val;
	    }
        
	}


	/**
	 * cache include
	 */
	private function cacheInclude($url, $handle, $type){

		if($type == "css"){	  //cache css

			self::$arrUrlCacheCss[$url] = true;
			self::$arrHandleCacheCss[$handle] = true;

		}else{
				//cache js

			self::$arrUrlCacheJs[$url] = true;
			self::$arrHandleCacheJs[$handle] = true;

		}

	}

	/**
	 * check that the include located in cache
	 */
	private function isIncludeInCache($url, $handle, $type){

		if(empty($url) || empty($handle))
			return(false);

		if($type == "css"){

			if(isset(self::$arrUrlCacheCss[$url]))
				return(true);

			if(isset(self::$arrHandleCacheCss[$handle]))
				return(true);

		}else{	//js

			if(isset(self::$arrUrlCacheJs[$url]))
				return(true);

			if(isset(self::$arrHandleCacheJs[$handle]))
				return(true);

		}

		return(false);
	}



	/**
	 * check include condition
	 * return true  to include and false to not include
	 */
	private function checkIncludeCondition($condition){

		if(empty($condition))
			return(true);

		if(!is_array($condition))
			return(true);

		$name = UniteFunctionsUC::getVal($condition, "name");
		$value = UniteFunctionsUC::getVal($condition, "value");

		if(empty($name))
			return(true);

		if($name == "never_include")
			return(false);

		$params = $this->getAddonParams();

		if(array_key_exists($name, $params) == false)
			return(true);

		$paramValue = $params[$name];

		if(is_array($value)){

			$index = array_search($paramValue, $value);

			$isEqual = ($index !== false);

		}else
			$isEqual = ($paramValue === $value);

		return($isEqual);
	}


	/**
	 * process includes list, get array("url", type)
	 */
	private function processIncludesList($arrIncludes, $type){

		$arrIncludesProcessed = array();

		foreach($arrIncludes as $handle => $include){

			$urlInclude = $include;

			if(is_array($include)){

				$urlInclude = UniteFunctionsUC::getVal($include, "url");
				$condition = UniteFunctionsUC::getVal($include, "condition");
				$isIncludeByCondition = $this->checkIncludeCondition($condition);

				if($isIncludeByCondition == false)
					continue;
			}

			if(is_numeric($handle) || empty($handle)){
				$addonName = $this->addon->getName();
				$handle = HelperUC::getUrlHandle($urlInclude, $addonName);
			}

			$urlInclude = HelperUC::urlToSSLCheck($urlInclude);

			$deps = array();

			$includeAsModule = false;

			//process params
			$params = UniteFunctionsUC::getVal($include, "params");
			if(!empty($params)){
				$includeAfterFrontend = UniteFunctionsUC::getVal($params, "include_after_elementor_frontend");
				$includeAfterFrontend = UniteFunctionsUC::strToBool($includeAfterFrontend);

				if($includeAfterFrontend == true)
					$deps[]= "elementor-frontend";

				//include as module handle.
				//add to handles array, and later check if need to add the module addition to output

				$includeAsModule = UniteFunctionsUC::getVal($params, "include_as_module");
				$includeAsModule = UniteFunctionsUC::strToBool($includeAsModule);

				if($includeAsModule == true)
					GlobalsProviderUC::$arrJSHandlesModules[$handle] = true;

				//change the handle
				$customHandle = UniteFunctionsUC::getVal($params, "include_handle");
				$customHandle = trim($customHandle);

				if(!empty($customHandle))
					$handle = $customHandle;

			}

			$arrIncludeNew = array();
			$arrIncludeNew["url"] = $urlInclude;
			$arrIncludeNew["type"] = $type;

			if(!empty($handle))
				$arrIncludeNew["handle"] = $handle;

			if(!empty($deps))
				$arrIncludeNew["deps"] = $deps;

			if($includeAsModule == true)
				$arrIncludeNew["is_module"] = true;


			$arrIncludesProcessed[] = $arrIncludeNew;

		}



		return($arrIncludesProcessed);
	}

	/**
	 * exclude alrady existing includes on page
	 * like font awesome
	 * function for override
	 */
	protected function excludeExistingInlcudes($arrIncludes){

		return($arrIncludes);
	}

	/**
	 * get processed includes list
	 * includes type = js / css / all
	 */
	public function getProcessedIncludes($includeLibraries = false, $processProviderLibrary = false, $includesType = "all"){

		$this->validateInited();

		//get list of js and css
		$arrLibJs = array();
		$arrLibCss = array();

		if($includeLibraries == true){
			//get all libraries without provider process
			$arrLibraries = $this->addon->getArrLibraryIncludesUrls($processProviderLibrary);
		}

		$arrIncludesJS = array();
		$arrIncludesCss = array();

		//get js
		if($includesType != "css"){
			if($includeLibraries)
				$arrLibJs = $arrLibraries["js"];

			$arrIncludesJS = $this->addon->getJSIncludes();
			$arrIncludesJS = array_merge($arrLibJs, $arrIncludesJS);
			$arrIncludesJS = $this->processIncludesList($arrIncludesJS, "js");
		}

		//get css
		if($includesType != "js"){
			if($includeLibraries)
				$arrLibCss = $arrLibraries["css"];

			$arrIncludesCss = $this->addon->getCSSIncludes();
			$arrIncludesCss = array_merge($arrLibCss, $arrIncludesCss);
			$arrIncludesCss = $this->processIncludesList($arrIncludesCss, "css");
		}

		$arrProcessedIncludes = array_merge($arrIncludesJS, $arrIncludesCss);
		$arrProcessedIncludes = $this->excludeExistingInlcudes($arrProcessedIncludes);
		
		// add widget scripts to editor
		if(!empty(HelperUC::$arrWidgetScripts)){
			foreach(HelperUC::$arrWidgetScripts as $handle => $urlScript){
				$arrScript = array(
					"type" => "js",
					"handle" => $handle,
					"url" => $urlScript,
				);

				$arrProcessedIncludes[] = $arrScript;
			}

			//empty the array
			HelperUC::$arrWidgetScripts = array();
		}

		
		return $arrProcessedIncludes;
	}


	/**
	 * get includes html
	 */
	private function getHtmlIncludes($arrIncludes = null, $filterType = null){

		$this->validateInited();

		if(empty($arrIncludes))
			return("");

		$addonName = $this->addon->getName();

		$html = "";

		foreach($arrIncludes as $include){

			$type = $include["type"];

			//filter
			if($filterType == "js" && $type != "js")
				continue;

			if($filterType == "css" && $type != "css")
				continue;

			$url = $include["url"];
			$handle = UniteFunctionsUC::getVal($include, "handle");

			if(empty($handle))
				$handle = HelperUC::getUrlHandle($url, $addonName);

			if( $handle == 'jquery' ) {
				if ( !wp_script_is( 'jquery', 'enqueued' ) ) {
					wp_enqueue_script( 'jquery' );
				}	
				continue;
			}
			
			$isInCache = $this->isIncludeInCache($url, $handle, $type);
			
			//if inside hidden template - no cache needed. should output the css each time.
			
			if($isInCache == true && GlobalsProviderUC::$isInsideHiddenTemplate !== true){
				
				continue;
			}

			$this->cacheInclude($url, $handle, $type);

			switch($type){
				case "js":

					$htmlType = "text/javascript";
					$isModule = UniteFunctionsUC::getVal($include, "is_module");
					$isModule = UniteFunctionsUC::strToBool($isModule);
					
					if($isModule == true)
						$htmlType = "module";
					// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript	
					$html .= self::TAB2."<script type='{$htmlType}' src='{$url}'></script>".self::BR;
					break;
				case "css":
					$cssID = "{$handle}-css";

					$isDelayedScript = apply_filters("uelm_is_style_delayed", $cssID);
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy hook name kept for existing callbacks.
					$isDelayedScript = apply_filters("unlimited_element_is_style_delayed", $cssID);

					if($isDelayedScript === true){
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
						$styleHtml = "<link id='{$cssID}' data-debloat-delay='' data-href='{$url}' type='text/css' rel='stylesheet' media='all' >";

						$html .= self::TAB2.$styleHtml.self::BR;
					}
					else
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
						$html .= self::TAB2."<link id='{$cssID}' href='{$url}' type='text/css' rel='stylesheet' >".self::BR;

					break;
				default:
					UniteFunctionsUC::throwError("Wrong include type: {$type} ");
				break;
			}

		}



		return($html);
	}
	
	
	/**
	 * process includes
	 * includes type = "all,js,css"
	 */
	public function processIncludes($includesType = "all"){
		
		$arrIncludes = $this->getProcessedIncludes(true, true, $includesType);
				
		$addonName = $this->addon->getName();

		$arrDep = $this->addon->getIncludesJsDependancies();

		foreach($arrIncludes as $include){
			
			$type = $include["type"];
			$url = $include["url"];
			$handle = UniteFunctionsUC::getVal($include, "handle");
			$deps = UniteFunctionsUC::getVal($include, "deps");

			if(empty($handle))
				$handle = HelperUC::getUrlHandle($url, $addonName);

			$isInCache = $this->isIncludeInCache($url, $handle, $type);
			if($isInCache == true){
				continue;
			}
			
			$this->cacheInclude($url, $handle, $type);
			
			$arrIncludeDep = $arrDep;

			if(!empty($deps))
				$arrIncludeDep = array_merge($arrIncludeDep, $deps);

			switch($type){
				case "js":
					
					//deregister script first if exists
					wp_deregister_script( $handle );
					
					UniteProviderFunctionsUC::addScript($handle, $url, false, $arrIncludeDep);
				
				break;
				case "css":
					
					wp_deregister_style($handle);
					
					UniteProviderFunctionsUC::addStyle($handle, $url);
				break;
				default:
					UniteFunctionsUC::throwError("Wrong include type: {$type} ");
				break;
			}

		}
		
		//process special includes if available
		
	}
	
	
	private function a________PREVIEW_HTML________(){}

	/**
	 * put header additions in header html, functiob for override
	 */
	protected function putPreviewHtml_headerAdd(){
	}

	/**
	 * put footer additions in body html, functiob for override
	 */
	protected function putPreviewHtml_footerAdd(){
	}

	/**
	 * function for override
	 */
	protected function onPreviewHtml_scriptsAdd(){
		/*function for override */
	}

	/**
	 * modify preview includes, function for override
	 */
	protected function modifyPreviewIncludes($arrIncludes){

		return($arrIncludes);
	}


	private function ______CSS_SELECTORS_______(){}

	/**
	 * process css selector of number param
	 */
    private function processParamCSSSelector_number($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        $isOldFormat = is_array($value) && !empty($value);
        
        if($isOldFormat){
            $values = array(
                "desktop" => $value,
                "tablet" => UniteFunctionsUC::getVal($param, "value_tablet"),
                "mobile" => UniteFunctionsUC::getVal($param, "value_mobile"),
            );
        } else {
            $values = array(
                "desktop" => UniteFunctionsUC::getVal($param, "value"),
                "tablet" => UniteFunctionsUC::getVal($param, "value_tablet"),
                "mobile" => UniteFunctionsUC::getVal($param, "value_mobile"),
            );
        }

        $style = "";

        foreach($values as $device => $value){
            if(empty($value) === true)
                continue;

            foreach($selectors as $selector => $selectorValue){
                $css = $this->prepareCSSSelectorValueCSS($selectorValue, $value);
                $style .= $this->prepareCSSSelectorStyle($selector, $css, $device);
            }
        }

        return $style;
    }

	/*
	 * process css selector of background param
    */
    private function processParamCSSSelector_background($param, $selectors){
        $name = UniteFunctionsUC::getVal($param, "name");
        $value = UniteFunctionsUC::getVal($param, "value");

        $isOldFormat = is_array($value) && !empty($value);
        
        if($isOldFormat){
            $type = UniteFunctionsUC::getVal($value, $name . "_type");
        } else {
            $type = UniteFunctionsUC::getVal($param, "background_type");
            $value = $param; 
        }

        $selector = $this->combineCSSSelectors($selectors);

        $style = '';

        switch($type){
            case "solid":
                $regularFields = array(
                    $name . "_solid_color" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "color"),
                    $name . "_solid_image_attachment" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "attachment"),
                );

                $responsiveFields = array(
                    $name . "_solid_image" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "image"),
                    $name . "_solid_image_position" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "position"),
                    $name . "_solid_image_repeat" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "repeat"),
                    $name . "_solid_image_size" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "size"),
                );

                // Нужно преобразовать ключи для нового формата
                if(!$isOldFormat){
                    // Для нового формата ключи без префикса name
                    $regularFields = array(
                        "solid_color" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "color"),
                        "solid_image_attachment" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "attachment"),
                    );

                    $responsiveFields = array(
                        "solid_bg_image" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "image"),
                        "solid_bg_image_position" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "position"),
                        "solid_bg_image_repeat" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "repeat"),
                        "solid_bg_image_size" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "size"),
                    );
                }

                $style .= $this->prepareCSSSelectorFieldsStyle($regularFields, $selector, $value);
                $style .= $this->prepareCSSSelectorResponsiveFieldsStyle($responsiveFields, $selector, $value);
            break;
            case "gradient":
                // Определяем префиксы ключей в зависимости от формата
                $prefix = $isOldFormat ? $name . "_" : "";
                
                $color1 = UniteFunctionsUC::getVal($value, $prefix . "gradient_color1");
                $stop1 = UniteFunctionsUC::getVal($value, $prefix . "gradient1_stop");
                $color2 = UniteFunctionsUC::getVal($value, $prefix . "gradient_color2");
                $stop2 = UniteFunctionsUC::getVal($value, $prefix . "gradient2_stop");
                $gradientType = UniteFunctionsUC::getVal($value, $prefix . "gradient_type");
                $angle = UniteFunctionsUC::getVal($value, $prefix . "gradient_angle");
                $position = UniteFunctionsUC::getVal($value, $prefix . "gradient_position");

                $stop1 = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $stop1);
                $stop2 = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $stop2);
                $angle = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $angle);

                if($color1 !== "" && $stop1 !== "" && $color2 !== "" && $stop2 !== "" && $gradientType !== "" && $angle !== "" && $position !== ""){
                    $selectorValue = ($gradientType === "radial")
                        ? HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "radial-gradient")
                        : HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BACKGROUND, "linear-gradient");

                    $css = $this->processCSSSelectorReplaces($selectorValue, array(
                        "{{angle}}" => $angle,
                        "{{position}}" => $position,
                        "{{color1}}" => $color1,
                        "{{stop1}}" => $stop1,
                        "{{color2}}" => $color2,
                        "{{stop2}}" => $stop2,
                    ));

                    $style .= $this->prepareCSSSelectorStyle($selector, $css);
                }
            break;
        }

        return $style;
    }

    private function processParamCSSSelector_border($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        $isOldFormat = is_array($value) && !empty($value);
        
        if($isOldFormat){
            // from block settings
            $type = UniteFunctionsUC::getVal($value, "type", "none");
            $color = UniteFunctionsUC::getVal($value, "color", "#000000");
        } else {
            // from block defaults
            $type = UniteFunctionsUC::getVal($param, "border_type", "none");
            $color = UniteFunctionsUC::getVal($param, "border_color", "#000000");
            $value = $param; // для единообразия
        }

        if($type === "none")
            return null;

        $style = "";
        $selector = $this->combineCSSSelectors($selectors);

        // border-style
        $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BORDER, "style");
        $css = $this->prepareCSSSelectorValueCSS($selectorValue, $type);
        $style .= $this->prepareCSSSelectorStyle($selector, $css);

        // border-color
        $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BORDER, "color");
        $css = $this->prepareCSSSelectorValueCSS($selectorValue, $color);
        $style .= $this->prepareCSSSelectorStyle($selector, $css);

        // border-width (responsive)
        $widths = array();
        
        if($isOldFormat){
            $widths = array(
                "desktop" => UniteFunctionsUC::getVal($value, "width"),
                "tablet" => UniteFunctionsUC::getVal($value, "width_tablet"),
                "mobile" => UniteFunctionsUC::getVal($value, "width_mobile"),
            );
        } else {
            // Новый формат: width_desktop_top, width_tablet_top, width_mobile_top
            $widths = array(
                "desktop" => array(
                    "top" => UniteFunctionsUC::getVal($value, "width_desktop_top"),
                    "right" => UniteFunctionsUC::getVal($value, "width_desktop_right"),
                    "bottom" => UniteFunctionsUC::getVal($value, "width_desktop_bottom"),
                    "left" => UniteFunctionsUC::getVal($value, "width_desktop_left"),
                    "unit" => "px"
                ),
                "tablet" => array(
                    "top" => UniteFunctionsUC::getVal($value, "width_tablet_top"),
                    "right" => UniteFunctionsUC::getVal($value, "width_tablet_right"),
                    "bottom" => UniteFunctionsUC::getVal($value, "width_tablet_bottom"),
                    "left" => UniteFunctionsUC::getVal($value, "width_tablet_left"),
                    "unit" => "px"
                ),
                "mobile" => array(
                    "top" => UniteFunctionsUC::getVal($value, "width_mobile_top"),
                    "right" => UniteFunctionsUC::getVal($value, "width_mobile_right"),
                    "bottom" => UniteFunctionsUC::getVal($value, "width_mobile_bottom"),
                    "left" => UniteFunctionsUC::getVal($value, "width_mobile_left"),
                    "unit" => "px"
                ),
            );
        }

        $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BORDER, "width");

        foreach($widths as $device => $widthValue){
            if(empty($widthValue) === true)
                continue;

            $css = $this->prepareCSSSelectorDimentionsCSS($selectorValue, $widthValue);
            $style .= $this->prepareCSSSelectorStyle($selector, $css, $device);
        }

        return $style;
    }

    /**
     * process css selector of dimentions param
     */
    private function processParamCSSSelector_dimentions($param, $selectors, $type){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        $isOldFormat = is_array($value) && !empty($value);
        
        if($isOldFormat){
            $values = array(
                "desktop" => $value,
                "tablet" => UniteFunctionsUC::getVal($param, "value_tablet"),
                "mobile" => UniteFunctionsUC::getVal($param, "value_mobile"),
            );
        } else {
            $values = array(
                "desktop" => UniteFunctionsUC::getVal($param, "value"),
                "tablet" => UniteFunctionsUC::getVal($param, "value_tablet"),
                "mobile" => UniteFunctionsUC::getVal($param, "value_mobile"),
            );
        }

        $style = "";
        $selector = $this->combineCSSSelectors($selectors);
        $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam($type);

        foreach($values as $device => $value){
            if(empty($value) === true)
                continue;

            $css = $this->prepareCSSSelectorDimentionsCSS($selectorValue, $value);
            $style .= $this->prepareCSSSelectorStyle($selector, $css, $device);
        }

        return $style;
    }

    /**
     * process css selector of slider param
     */
    private function processParamCSSSelector_slider($param, $selectors){
        $name = UniteFunctionsUC::getVal($param, "name");
        $value = UniteFunctionsUC::getVal($param, "value");
        
        $isOldFormat = is_array($value) && isset($value["size"]);
        
        if($isOldFormat){
            $tabletValue = UniteFunctionsUC::getVal($param, "value_tablet");
            $mobileValue = UniteFunctionsUC::getVal($param, "value_mobile");
            
            $values = array(
                "desktop" => $value,
                "tablet" => is_array($tabletValue) && isset($tabletValue["size"]) ? $tabletValue : null,
                "mobile" => is_array($mobileValue) && isset($mobileValue["size"]) ? $mobileValue : null,
            );
        } else {
            $defaultValue = UniteFunctionsUC::getVal($param, "default_value");
            $defaultValueTablet = UniteFunctionsUC::getVal($param, "default_value_tablet");
            $defaultValueMobile = UniteFunctionsUC::getVal($param, "default_value_mobile");
            
            $desktopValue = UniteFunctionsUC::getVal($param, "value");
            if(empty($desktopValue) && $desktopValue !== "0") $desktopValue = $defaultValue;
            
            $tabletValue = UniteFunctionsUC::getVal($param, "value_tablet");
            if(empty($tabletValue) && $tabletValue !== "0") $tabletValue = $defaultValueTablet;
            
            $mobileValue = UniteFunctionsUC::getVal($param, "value_mobile");
            if(empty($mobileValue) && $mobileValue !== "0") $mobileValue = $defaultValueMobile;
            
            $desktopUnit = UniteFunctionsUC::getVal($param, "unit");
            $tabletUnit = UniteFunctionsUC::getVal($param, "unit_tablet");
            $mobileUnit = UniteFunctionsUC::getVal($param, "unit_mobile");
            
            $defaultUnit = UniteFunctionsUC::getVal($param, "units");
            if(empty($defaultUnit)) $defaultUnit = "px";

            if(strpos($defaultUnit, "_") !== false){
                $unitsArray = explode("_", $defaultUnit);
                $defaultUnit = $unitsArray[0];
            }
            
            if(empty($desktopUnit)) $desktopUnit = $defaultUnit;
            if(empty($tabletUnit)) $tabletUnit = $desktopUnit;
            if(empty($mobileUnit)) $mobileUnit = $desktopUnit;
            
            $values = array(
                "desktop" => (!empty($desktopValue) || $desktopValue === "0") ? array("size" => (int)$desktopValue, "unit" => $desktopUnit) : null,
                "tablet" => (!empty($tabletValue) || $tabletValue === "0") ? array("size" => (int)$tabletValue, "unit" => $tabletUnit) : null,
                "mobile" => (!empty($mobileValue) || $mobileValue === "0") ? array("size" => (int)$mobileValue, "unit" => $mobileUnit) : null,
            );
        }

        $style = "";

        // $style .= "/* =[12] " . json_encode($param) . " */ ";

        foreach($values as $device => $value){
            if($value === null || (is_array($value) && empty($value)))
                continue;
                
            if(is_array($value) && (!isset($value["size"]) || $value["size"] === ""))
                continue;

            foreach($selectors as $selector => $selectorValue){
                $css = $this->prepareCSSSelectorSliderCSS($selectorValue, $value);
                $style .= $this->prepareCSSSelectorStyle($selector, $css, $device);
            }
        }

        return $style;
    }

    /**
     * process css selector of typography param
     */
    private function processParamCSSSelector_typography($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        // Проверяем, является ли $value массивом с данными (старый формат)
        $isOldFormat = is_array($value) && !empty($value);
        
        if(!$isOldFormat){
            // Новый формат: значения прямо в $param
            $value = $param;
        }

        $style = "";
        $selector = $this->combineCSSSelectors($selectors);

        // import font family
        $fontFamily = UniteFunctionsUC::getVal($value, "font_family");

        if(empty($fontFamily) === false){
            $fontData = HelperUC::getFontPanelData();
            $googleFonts = UniteFunctionsUC::getVal($fontData, "arrGoogleFonts");

            if(empty($googleFonts[$fontFamily]) === false){
                $fontUrl = HelperHtmlUC::getGoogleFontUrl($googleFonts[$fontFamily]);

                $this->addon->addCssInclude($fontUrl);
            }
        }

        $regularFields = array(
            "font_family" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "family"),
            "font_style" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "style"),
            "font_weight" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "weight"),
            "text_decoration" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "decoration"),
            "text_transform" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "transform"),
        );

        $responsiveFields = array(
            "font_size" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "size"),
            "line_height" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "line-height"),
            "letter_spacing" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "letter-spacing"),
            "word_spacing" => HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TYPOGRAPHY, "word-spacing"),
        );

        $style .= $this->prepareCSSSelectorFieldsStyle($regularFields, $selector, $value);
        $style .= $this->prepareCSSSelectorResponsiveFieldsStyle($responsiveFields, $selector, $value);

        return $style;
    }

    /**
     * process css selector of text shadow param
     */
    private function processParamCSSSelector_textShadow($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        // Проверяем, является ли $value массивом с данными (старый формат)
        $isOldFormat = is_array($value) && !empty($value);
        
        if(!$isOldFormat){
            // Новый формат: значения прямо в $param
            $value = $param;
        }

        $x = UniteFunctionsUC::getVal($value, "x");
        $y = UniteFunctionsUC::getVal($value, "y");
        $blur = UniteFunctionsUC::getVal($value, "blur");
        $color = UniteFunctionsUC::getVal($value, "color");

        $x = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $x);
        $y = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $y);
        $blur = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $blur);

        $css = "";

        if($x !== "" && $y !== "" && $blur !== "" && $color !== ""){
            $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TEXTSHADOW);

            $css = $this->processCSSSelectorReplaces($selectorValue, array(
                "{{x}}" => $x,
                "{{y}}" => $y,
                "{{blur}}" => $blur,
                "{{color}}" => $color,
            ));
        }

        $selector = $this->combineCSSSelectors($selectors);
        $style = $this->prepareCSSSelectorStyle($selector, $css);

        return $style;
    }

    private function processParamCSSSelector_textStroke($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        // Проверяем, является ли $value массивом с данными (старый формат)
        $isOldFormat = is_array($value) && !empty($value);
        
        if(!$isOldFormat){
            // Новый формат: значения прямо в $param
            $value = $param;
        }

        $width = UniteFunctionsUC::getVal($value, "width");
        $color = UniteFunctionsUC::getVal($value, "color");

        // Prepare CSS for stroke width
        $width = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $width);

        $css = "";

        // If both width and color are available, apply the text stroke
        if($width !== "" && $color !== ""){
            $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_TEXTSTROKE);

            $css = $this->processCSSSelectorReplaces($selectorValue, array(
                "{{width}}" => $width,
                "{{color}}" => $color,
            ));
        }

        // Combine the selectors and prepare the style
        $selector = $this->combineCSSSelectors($selectors);
        $style = $this->prepareCSSSelectorStyle($selector, $css);

        return $style;
    }

    /**
     * process css selector of box shadow param
     */
    private function processParamCSSSelector_boxShadow($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        // Проверяем, является ли $value массивом с данными (старый формат)
        $isOldFormat = is_array($value) && !empty($value);
        
        if(!$isOldFormat){
            // Новый формат: значения прямо в $param
            $value = $param;
        }

        $x = UniteFunctionsUC::getVal($value, "x");
        $y = UniteFunctionsUC::getVal($value, "y");
        $blur = UniteFunctionsUC::getVal($value, "blur");
        $spread = UniteFunctionsUC::getVal($value, "spread");
        $color = UniteFunctionsUC::getVal($value, "color");
        $position = UniteFunctionsUC::getVal($value, "position");

        $x = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $x);
        $y = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $y);
        $blur = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $blur);
        $spread = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $spread);

        $css = "";

        if($x !== "" && $y !== "" && $blur !== "" && $spread !== "" && $color !== "" && $position !== ""){
            $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_BOXSHADOW);

            $css = $this->processCSSSelectorReplaces($selectorValue, array(
                "{{x}}" => $x,
                "{{y}}" => $y,
                "{{blur}}" => $blur,
                "{{spread}}" => $spread,
                "{{color}}" => $color,
                "{{position}}" => $position,
            ));
        }

        $selector = $this->combineCSSSelectors($selectors);
        $style = $this->prepareCSSSelectorStyle($selector, $css);

        return $style;
    }

    /**
     * process css selector of css filters param
     */
    private function processParamCSSSelector_cssFilters($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        
        // Проверяем, является ли $value массивом с данными (старый формат)
        $isOldFormat = is_array($value) && !empty($value);
        
        if(!$isOldFormat){
            // Новый формат: значения прямо в $param
            $value = $param;
        }

        $blur = UniteFunctionsUC::getVal($value, "blur");
        $brightness = UniteFunctionsUC::getVal($value, "brightness");
        $contrast = UniteFunctionsUC::getVal($value, "contrast");
        $saturation = UniteFunctionsUC::getVal($value, "saturation");
        $hue = UniteFunctionsUC::getVal($value, "hue");

        $blur = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $blur);
        $brightness = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $brightness);
        $contrast = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $contrast);
        $saturation = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $saturation);
        $hue = $this->prepareCSSSelectorSliderCSS(self::SELECTOR_VALUE_PLACEHOLDER, $hue);

        $css = "";

        if($blur !== "" && $brightness !== "" && $contrast !== "" && $saturation !== "" && $hue !== ""){
            $selectorValue = HelperHtmlUC::getCSSSelectorValueByParam(UniteCreatorDialogParam::PARAM_CSS_FILTERS);

            $css = $this->processCSSSelectorReplaces($selectorValue, array(
                "{{blur}}" => $blur,
                "{{brightness}}" => $brightness,
                "{{contrast}}" => $contrast,
                "{{saturate}}" => $saturation,
                "{{hue}}" => $hue,
            ));
        }

        $selector = $this->combineCSSSelectors($selectors);
        $style = $this->prepareCSSSelectorStyle($selector, $css);

        return $style;
    }


    /**
     * process css selector based on value
     */
    private function processParamCSSSelector_value($param, $selectors){
        $value = UniteFunctionsUC::getVal($param, "value");
        $name = UniteFunctionsUC::getVal($param, "name");
        
        $isOldFormat = is_array($value) && !empty($value);
        
        if($isOldFormat){
            $values = array(
                "desktop" => $value,
                "tablet" => UniteFunctionsUC::getVal($param, "value_tablet"),
                "mobile" => UniteFunctionsUC::getVal($param, "value_mobile"),
            );
        } else {
            // Получаем значения или используем default_value
            $defaultValue = UniteFunctionsUC::getVal($param, "default_value");
            $defaultValueTablet = UniteFunctionsUC::getVal($param, "default_value_tablet");
            $defaultValueMobile = UniteFunctionsUC::getVal($param, "default_value_mobile");
            
            $desktopValue = UniteFunctionsUC::getVal($param, "value");
            if(empty($desktopValue) && $desktopValue !== "0") $desktopValue = $defaultValue;
            
            $tabletValue = UniteFunctionsUC::getVal($param, "value_tablet");
            if(empty($tabletValue) && $tabletValue !== "0") $tabletValue = $defaultValueTablet;
            
            $mobileValue = UniteFunctionsUC::getVal($param, "value_mobile");
            if(empty($mobileValue) && $mobileValue !== "0") $mobileValue = $defaultValueMobile;
            
            $values = array(
                "desktop" => $desktopValue,
                "tablet" => $tabletValue,
                "mobile" => $mobileValue,
            );
        }

        $options = UniteFunctionsUC::getVal($param, "options");

        if(empty($options) === false){
            $phpFilter = UniteFunctionsUC::getVal($param, "php_filter_name");

            if(empty($phpFilter) === false)
                $options = array_flip($options);

            foreach($values as $device => $value){
                if(in_array($value, $options) === false)
                    unset($values[$device]);
            }
        }

        $style = "";

        // $style .= "/* " . json_encode($param) . " */ /* " . $name . ": start */ ";

        foreach($values as $device => $value){
            // Улучшенная проверка пустых значений
            if($value === null || $value === "")
                continue;

            foreach($selectors as $selector => $selectorValue){
                $css = $this->prepareCSSSelectorValueCSS($selectorValue, $value);
                $style .= $this->prepareCSSSelectorStyle($selector, $css, $device);
            }
        }

        // $style .= "/* " . $name . ": end */ ";

        return $style;
    }


	/**
	 * prepare css selector dimentions css
	 */
    function prepareCSSSelectorDimentionsCSS($selectorValue, $value){

        $top    = UniteFunctionsUC::getVal($value, "top");
        $right  = UniteFunctionsUC::getVal($value, "right");
        $bottom = UniteFunctionsUC::getVal($value, "bottom");
        $left   = UniteFunctionsUC::getVal($value, "left");
        $unit   = UniteFunctionsUC::getVal($value, "unit", "px");

        $rawSides = array($top, $right, $bottom, $left);
        $hasValue = false;

        foreach ($rawSides as $sideValue) {
            if ($sideValue !== '' && $sideValue !== null && $sideValue !== false) {
                $sideValue = trim((string)$sideValue);
                if ($sideValue !== '') {
                    $hasValue = true;
                    break;
                }
            }
        }

        if ($hasValue === false) return "";

        $prepareSide = function($v) use ($unit) {
            if ($v === '' || $v === null || $v === false)
                return '';

            $v = trim((string)$v);
            if ($v === '')
                return '';

            return $v . $unit;
        };

        $css = $this->processCSSSelectorReplaces($selectorValue, array(
            "{{top}}"    => $prepareSide($top),
            "{{right}}"  => $prepareSide($right),
            "{{bottom}}" => $prepareSide($bottom),
            "{{left}}"   => $prepareSide($left),
        ));

        return $css;
    }

	/**
	 * prepare css selector image css
	 */
	private function prepareCSSSelectorImageCSS($selectorValue, $value){

		$id = UniteFunctionsUC::getVal($value, "id");
		$url = UniteFunctionsUC::getVal($value, "url");
		$size = UniteFunctionsUC::getVal($value, "size", "full");

		if(empty($id) === false)
			$url = UniteProviderFunctionsUC::getImageUrlFromImageID($id, $size);
		else
			$url = HelperUC::URLtoFull($url);

		$css = $this->prepareCSSSelectorValueCSS($selectorValue, $url);

		return $css;
	}

	/**
	 * prepare css selector slider css
	 */
	private function prepareCSSSelectorSliderCSS($selectorValue, $value){

		$size = UniteFunctionsUC::getVal($value, "size");
		$unit = UniteFunctionsUC::getVal($value, "unit", "px");

		if($size === "")
			return "";

		$css = $this->processCSSSelectorReplaces($selectorValue, array(
			self::SELECTOR_VALUE_PLACEHOLDER => $size . $unit,
			"{{size}}" => $size,
			"{{unit}}" => $unit,
		));

		return $css;
	}

	/**
	 * prepare css selector value css
	 */
	private function prepareCSSSelectorValueCSS($selectorValue, $value){

		if($value === null || $value === "")
			return "";

		$css = $this->processCSSSelectorReplaces($selectorValue, array(self::SELECTOR_VALUE_PLACEHOLDER => $value));

		return $css;
	}

	/**
	 * prepare css selector field css
	 */
	private function prepareCSSSelectorFieldCSS($selectorValue, $value){

		if (is_array($value) === false)
			return $this->prepareCSSSelectorValueCSS($selectorValue, $value);

		if(array_key_exists("top", $value) === true
			&& array_key_exists("right", $value) === true
			&& array_key_exists("bottom", $value) === true
			&& array_key_exists("left", $value) === true
			&& array_key_exists("unit", $value) === true)
			return $this->prepareCSSSelectorDimentionsCSS($selectorValue, $value);

		if(array_key_exists("id", $value) === true
			&& array_key_exists("url", $value) === true
			&& array_key_exists("size", $value) === true)
			return $this->prepareCSSSelectorImageCSS($selectorValue, $value);

		if(array_key_exists("size", $value) === true
			&& array_key_exists("unit", $value) === true)
			return $this->prepareCSSSelectorSliderCSS($selectorValue, $value);
		
		return "";
		
		UniteFunctionsUC::throwError(__FUNCTION__ . " Error: Value processing is not implemented (" . json_encode($value) . ")");
	}

	/**
	 * prepare css selector fields style
	 */
	private function prepareCSSSelectorFieldsStyle($fields, $selector, $value){

		$css = "";

		foreach($fields as $fieldName => $selectorValue){
			$fieldValue = UniteFunctionsUC::getVal($value, $fieldName);
			$css .= $this->prepareCSSSelectorFieldCSS($selectorValue, $fieldValue);
		}

		return $this->prepareCSSSelectorStyle($selector, $css);
	}

	/**
	 * prepare css selector responsive fields style
	 */
	private function prepareCSSSelectorResponsiveFieldsStyle($fields, $selector, $value){

		$style = "";

		$responsive = array(
			"desktop" => "",
			"tablet" => "_tablet",
			"mobile" => "_mobile",
		);

		foreach($responsive as $device => $suffix){
			$css = "";

			foreach($fields as $fieldName => $selectorValue){
				$fieldValue = UniteFunctionsUC::getVal($value, $fieldName . $suffix);
				$css .= $this->prepareCSSSelectorFieldCSS($selectorValue, $fieldValue);
			}

			$style .= $this->prepareCSSSelectorStyle($selector, $css, $device);
		}

		return $style;
	}

	/**
	 * prepare css selector style
	 */
	private function prepareCSSSelectorStyle($selector, $css, $device = "desktop"){

		if(empty($css) === true)
			return "";

		$style = $selector . "{" . $css . "}";

		switch($device){
			case "tablet":
				$style = HelperHtmlUC::wrapCssMobile($style, true);
			break;
			case "mobile":
				$style = HelperHtmlUC::wrapCssMobile($style);
			break;
		}

		return $style;
	}

	/**
	 * prepare css selector
	 */
	private function prepareCSSSelector($selector){

		$wrapperId = $this->getWidgetWrapperID();

		$selectors = explode(",", $selector);
		$selectors = array_filter($selectors);
		$selectors = array_unique($selectors);

		foreach($selectors as $index => $selector){
			$selectors[$index] = ".ue-widget-root." . $wrapperId . " " . trim($selector);
		}

		return implode(",", $selectors);
	}

	/**
	 * combine css selectors
	 */
	private function combineCSSSelectors($selectors){

		$selectors = array_keys($selectors);

		return implode(",", $selectors);
	}

	/**
	 * process css selector replaces
	 */
	private function processCSSSelectorReplaces($css, $replaces){

		foreach($replaces as $placeholder => $replace){
			$css = str_replace(strtolower($placeholder), $replace, $css);
			$css = str_replace(strtoupper($placeholder), $replace, $css);
		}

		return $css;
	}

	/**
	 * prepare param css selectors
	 */
	private function prepareParamCSSSelectors($param){

		$keys = array("selector", "selector1", "selector2", "selector3");
		$selectors = array();

		foreach($keys as $key){
			$selector = UniteFunctionsUC::getVal($param, $key);
			$selectorValue = UniteFunctionsUC::getVal($param, $key . "_value");

			if(empty($selector) === true)
				continue;

			$selector = $this->prepareCSSSelector($selector);

			$selectors[$selector] = $selectorValue;
		}

		return $selectors;
	}

	/**
	 * process params css selector
	 */
    private function processParamsCSSSelector($params, $paramsCats = array()){

        $styles = '';

        $displayCats = array();
        foreach($paramsCats as $cat) {
            $catID = UniteFunctionsUC::getVal($cat, "id");
            if(empty($catID))
                continue;
            $displayCats[$catID] = UEParamsManager::isParamPassesConditions($params, $cat);
        }

        foreach($params as $param){
            $passed = UEParamsManager::isParamPassesConditions($params, $param);
            if($passed === false) {
                continue;
            }
                

            // param's cat disabled
            $catID = UniteFunctionsUC::getVal($param, GlobalsUC::ATTR_CATID);
            $isDisplayCat = UniteFunctionsUC::getVal($displayCats, $catID);
            $isDisplayCat == UniteFunctionsUC::strToBool($isDisplayCat);

            if(!empty($catID) && $isDisplayCat == false) {
                continue;
            }

            $style = $this->processParamCSSSelector($param);

            if(empty($style) === false)
                $styles .= $style;
        }

        return $styles;
    }

    /**
     * process advanced tab css selectors
    */
    public function getAdvancedTabCssSelectors() {

        $styles = '';

        $wrapperId = $this->getWidgetWrapperID();
        $paramsTmp = $this->getAddonParams();

        // Get default values for advanced parameters
        $allParams = $this->addon->getParams();
        $advancedDefaults = array();

        foreach($allParams as $param) {
            $paramName = UniteFunctionsUC::getVal($param, 'name');
            
            // Only process advanced_ parameters
            if (strpos($paramName, 'advanced_') === 0) {
                $defaultValue = UniteFunctionsUC::getVal($param, 'default_value');
                
                // Check if default value exists and is not empty
                if ($defaultValue !== null && $defaultValue !== '' && $defaultValue !== false) {
                    // Only add if not already set by user
                    $currentValue = UniteFunctionsUC::getVal($paramsTmp, $paramName);
                    if ($currentValue === '' || $currentValue === null) {
                        $advancedDefaults[$paramName] = $defaultValue;
                    }
                }
            }
        }

        // Merge defaults with user-set values (user values take precedence)
        if (!empty($advancedDefaults)) {
            $paramsTmp = array_merge($advancedDefaults, $paramsTmp);
        }

        $advanced_styles = '';

        // z-index
        $zIndexVal = UniteFunctionsUC::getVal($paramsTmp, 'advanced_z_index');
        if ($zIndexVal !== '' && $zIndexVal !== null) {
            $zIndex = (int)$zIndexVal['size'];
            $advanced_styles .= 'z-index: ' . $zIndex . '; ';
        }

        $sides = ['top', 'right', 'bottom', 'left'];

        // padding
        $padding = UniteFunctionsUC::getVal($paramsTmp, 'advanced_padding');
        if (is_array($padding)) {

            $unit = !empty($padding['unit']) ? $padding['unit'] : 'px';

            if (!empty($padding['is_linked']) && !empty($padding['top'])) {
                $val = trim((string)$padding['top']);

                if ($val !== '') {
                    $advanced_styles .= 'padding: ' . $val . $unit . '; ';
                }
            } else {
                foreach($sides as $side) {
                    $val = UniteFunctionsUC::getVal($padding, $side);

                    if ($val === '' || $val === null || $val === false) {
                        continue;
                    }

                    $val = trim((string)$val);
                    if ($val === '') {
                        continue;
                    }

                    $advanced_styles .= 'padding-' . $side . ': ' . $val . $unit . '; ';
                }
            }
        }

        // margin
        $margin = UniteFunctionsUC::getVal($paramsTmp, 'advanced_margin');
        if (is_array($margin)) {
            if(!empty($margin['is_linked']) && $margin['is_linked'] && $margin['top'] !== '' && $margin['top'] !== null) {
                $val = (string)$margin['top'];
                if($val !== '' && $val !== null) {
                    $advanced_styles .= 'margin: ' . $val . $margin['unit'] . '; ';
                }
            } else {
                foreach($sides as $side) {
                    $val = UniteFunctionsUC::getVal($margin, $side);
                    if($val !== '' && $val !== null && $val !== false) {
                        $advanced_styles .= 'margin-' . $side . ': ' . (string)$val . $margin['unit'] . '; ';
                    }
                }
            }
        }

        // background
        $bgType = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_type'); 

        if (!empty($bgType)) {

            if ($bgType === 'solid') {
                $bgColor = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_color');
                if (empty($bgColor)) {
                    $bgColor = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_solid_color');
                }
                if (!empty($bgColor)) {
                    $advanced_styles .= 'background-color:' . $bgColor . '; ';
                }

                $bgImage = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_image');
                if (empty($bgImage)) {
                    $bgImage = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_solid_image');
                }

                $bgUrl = '';
                if (is_array($bgImage)) {
                    $attachment_id = UniteFunctionsUC::getVal($bgImage, 'id');
                    if ($attachment_id) {
                        $bgUrl = wp_get_attachment_url($attachment_id);
                    } else {
                        $bgUrl = UniteFunctionsUC::getVal($bgImage, 'url');
                    }
                } elseif (is_string($bgImage)) {
                    $bgUrl = $bgImage;
                }
                if (!empty($bgUrl)) {
                    if (strpos($bgUrl, 'http') !== 0) {
                        $uploads = wp_upload_dir();
                        $bgUrl = rtrim($uploads['baseurl'], '/') . '/' . ltrim($bgUrl, '/');
                    }
                }

                if (!empty($bgUrl)) {
                    if (stripos($bgUrl, 'url(') === false) {
                        $advanced_styles .= "background-image:url('".$bgUrl."'); ";
                    } else {
                        $advanced_styles .= 'background-image:' . $bgUrl . '; ';
                    }

                    $bgRepeat = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_repeat');
                    if (empty($bgRepeat)) {
                        $bgRepeat = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_solid_image_repeat');
                    }
                    if (!empty($bgRepeat)) {
                        $advanced_styles .= 'background-repeat:' . $bgRepeat . '; ';
                    }

                    $bgPos = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_position');
                    if (empty($bgPos)) {
                        $bgPos = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_solid_image_position');
                    }
                    if (!empty($bgPos)) {
                        $advanced_styles .= 'background-position:' . $bgPos . '; ';
                    }

                    $bgSize = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_size');
                    if (empty($bgSize)) {
                        $bgSize = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_solid_image_size');
                    }
                    if (!empty($bgSize)) {
                        $advanced_styles .= 'background-size:' . $bgSize . '; ';
                    }

                    $bgAttach = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_attachment');
                    if (empty($bgAttach)) {
                        $bgAttach = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_solid_image_attachment');
                    }
                    if (!empty($bgAttach)) {
                        $advanced_styles .= 'background-attachment:' . $bgAttach . '; ';
                    }
                }

            } elseif ($bgType === 'gradient') {

                $c1 = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_gradient1_color');
                $c2 = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_gradient2_color');

                $stop1Raw = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_gradient1_stop');
                $stop2Raw = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_gradient2_stop');

                $stop1 = '';
                if (is_array($stop1Raw)) {
                    $s  = UniteFunctionsUC::getVal($stop1Raw, 'size', '');
                    $u  = UniteFunctionsUC::getVal($stop1Raw, 'unit', '%');
                    if ($s !== '' && $s !== null) $stop1 = trim($s) . (empty($u) ? '%' : $u);
                } elseif (is_numeric($stop1Raw)) {
                    $stop1 = $stop1Raw . '%';
                }

                $stop2 = '';
                if (is_array($stop2Raw)) {
                    $s  = UniteFunctionsUC::getVal($stop2Raw, 'size', '');
                    $u  = UniteFunctionsUC::getVal($stop2Raw, 'unit', '%');
                    if ($s !== '' && $s !== null) $stop2 = trim($s) . (empty($u) ? '%' : $u);
                } elseif (is_numeric($stop2Raw)) {
                    $stop2 = $stop2Raw . '%';
                }

                $gType = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_gradient_type', 'linear');
                $gType = strtolower(trim((string)$gType));
                if ($gType !== 'radial') {
                    $gType = 'linear';
                }

                $angleRaw = UniteFunctionsUC::getVal($paramsTmp, 'advanced_background_gradient_angle');
                $angle    = 180; 
                if (is_array($angleRaw)) {
                    $sz = UniteFunctionsUC::getVal($angleRaw, 'size');
                    if ($sz !== '' && $sz !== null && is_numeric($sz)) {
                        $angle = (int)$sz;
                    }
                } elseif ($angleRaw !== '' && $angleRaw !== null && is_numeric($angleRaw)) {
                    $angle = (int)$angleRaw;
                }

                $angle = $angle % 360;
                if ($angle < 0) $angle += 360;

                if (!empty($c1)) {
                    $advanced_styles .= 'background-color:' . $c1 . '; ';
                }

                if ($gType === 'linear') {
                    $parts = [];
                    $parts[] = $c1 . (!empty($stop1) ? ' ' . $stop1 : '');
                    $parts[] = $c2 . (!empty($stop2) ? ' ' . $stop2 : '');
                    $advanced_styles .= 'background-image: linear-gradient(' . $angle . 'deg, ' . implode(', ', $parts) . '); ';
                } else {
                    $parts = [];
                    $parts[] = $c1 . (!empty($stop1) ? ' ' . $stop1 : '');
                    $parts[] = $c2 . (!empty($stop2) ? ' ' . $stop2 : '');
                    $advanced_styles .= 'background-image: radial-gradient(circle, ' . implode(', ', $parts) . '); ';
                }

            }
        }

        // border
        $borderType = UniteFunctionsUC::getVal($paramsTmp, 'advanced_border_type'); 
        $bw         = UniteFunctionsUC::getVal($paramsTmp, 'advanced_border_width'); 
        $bColor     = UniteFunctionsUC::getVal($paramsTmp, 'advanced_border_color');

        if (is_string($borderType)) {
            $borderType = trim($borderType);
        }

        if (!empty($borderType) && $borderType !== 'none') {

            if (empty($bColor)) {
                $bColor = '#000';
            }

            if (is_array($bw) && !empty($bw['is_linked']) && $bw['is_linked'] && $bw['top'] !== '' && $bw['top'] !== null) {
                $unit = !empty($bw['unit']) ? $bw['unit'] : 'px';
                $w    = (string)$bw['top'];

                $advanced_styles .= 'border:' . $w . $unit . ' ' . $borderType . ' ' . $bColor . '; ';

            } else {
                $unit = 'px';
                if (is_array($bw) && !empty($bw['unit'])) {
                    $unit = $bw['unit'];
                }

                $advanced_styles .= 'border-style:' . $borderType . '; border-color:' . $bColor . '; ';

                if (is_array($bw)) {
                    foreach (array('top','right','bottom','left') as $side) {
                        $val = UniteFunctionsUC::getVal($bw, $side);
                        if ($val !== '' && $val !== null && $val !== false) {
                            $advanced_styles .= 'border-' . $side . '-width:' . (string)$val . $unit . '; ';
                        }
                    }
                }
            }
        }

        if($advanced_styles != '') {
            $styles .= "\n." . $wrapperId . " {" . $advanced_styles . "}";
        }

        // -------------------- RESPONSIVE VISIBILITY --------------------
        $hideDesktop = UniteFunctionsUC::getVal($paramsTmp, 'advanced_hide_on_desktop');
        $hideTablet  = UniteFunctionsUC::getVal($paramsTmp, 'advanced_hide_on_tablet');
        $hideMobile  = UniteFunctionsUC::getVal($paramsTmp, 'advanced_hide_on_mobile');

        $isAdmin = function_exists('is_admin') && is_admin();

        if ($isAdmin) {
            if ((int)$hideDesktop) {
                $styles .= "\n@media (min-width:1025px){ #{$wrapperId}{ opacity:.55; filter:blur(1px) grayscale(1); pointer-events:none; } }";
            }
            if ((int)$hideTablet) {
                $styles .= "\n@media (min-width:768px) and (max-width:1024px){ #{$wrapperId}{ opacity:.55; filter:blur(1px) grayscale(1); pointer-events:none; } }";
            }
            if ((int)$hideMobile) {
                $styles .= "\n@media (max-width:767px){ #{$wrapperId}{ opacity:.55; filter:blur(1px) grayscale(1); pointer-events:none; } }";
            }
        } else {
            if ((int)$hideDesktop) {
                $styles .= "\n@media (min-width:1025px){ #{$wrapperId}{ display:none !important; } }";
            }
            if ((int)$hideTablet) {
                $styles .= "\n@media (min-width:768px) and (max-width:1024px){ #{$wrapperId}{ display:none !important; } }";
            }
            if ((int)$hideMobile) {
                $styles .= "\n@media (max-width:767px){ #{$wrapperId}{ display:none !important; } }";
            }
        }
        
        return $styles;
    }

	/**
	 * process param css selector
	 */
	private function processParamCSSSelector($param){

		$selectors = $this->prepareParamCSSSelectors($param);

		if(empty($selectors) === true)
			return null;

		$type = UniteFunctionsUC::getVal($param, "type");

		switch($type){
			case UniteCreatorDialogParam::PARAM_NUMBER:
				$style = $this->processParamCSSSelector_number($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_BACKGROUND:
				$style = $this->processParamCSSSelector_background($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_BORDER:
				$style = $this->processParamCSSSelector_border($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_PADDING:
			case UniteCreatorDialogParam::PARAM_MARGINS:
			case UniteCreatorDialogParam::PARAM_BORDER_DIMENTIONS:
				$style = $this->processParamCSSSelector_dimentions($param, $selectors, $type);
			break;
			case UniteCreatorDialogParam::PARAM_SLIDER:
				$style = $this->processParamCSSSelector_slider($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_TYPOGRAPHY:
				$style = $this->processParamCSSSelector_typography($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_TEXTSHADOW:
				$style = $this->processParamCSSSelector_textShadow($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_TEXTSTROKE:
				$style = $this->processParamCSSSelector_textStroke($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_BOXSHADOW:
				$style = $this->processParamCSSSelector_boxShadow($param, $selectors);
			break;
			case UniteCreatorDialogParam::PARAM_CSS_FILTERS:
				$style = $this->processParamCSSSelector_cssFilters($param, $selectors);
			break;
			default:
				$style = $this->processParamCSSSelector_value($param, $selectors);
			break;
		}

		return $style;
	}

	/**
	 * check what params has selectors in them, and include their css
	 */
	private function processPreviewParamsSelectors(){

		$styles = "";

		$mainParams = $this->addon->getParams();

		$paramsCats = $this->addon->getParamsCats();

		if(empty($mainParams) === false)
			$styles .= $this->processParamsCSSSelector($mainParams, $paramsCats);

		$styles .= $this->processItemsSelectors();

        $styles .= $this->getAdvancedTabCssSelectors();

		if(empty($styles) === true)
			return null;

		return $styles;
	}

    private function getProcessedItemsData() {
        if(empty($this->processedItemsData)) {
            $this->processedItemsData = $this->addon->getProcessedItemsData($this->processType);
        }
        return $this->processedItemsData;
    }

	/**
	 * process items selectors
	 */
	private function processItemsSelectors(){

		$styles = "";

		$items = $this->addon->getArrItemsNonProcessed();
        $itemsProcessed = $this->getProcessedItemsData();
		$params = $this->addon->getProcessedItemsParams();

        // $styles = '/* =[8] ' . json_encode($items) . ' =[9] */';

		if (empty($items) === true || empty($params) === true)
			return $styles;

		foreach($items as $k => $item){
            $itemData = $itemsProcessed[$k]['item'];
			$itemId = UniteFunctionsUC::getVal($itemData, "_generated_id");
            $itemParams = array();

			foreach($params as $param){
				$paramName = UniteFunctionsUC::getVal($param, "name");
				$itemValue = UniteFunctionsUC::getVal($item, $paramName);

				$itemParams[] = array_merge($param, array("value" => $itemValue));
			}

			$itemStyles = $this->processParamsCSSSelector($itemParams);
			
            $itemStyles = $this->processCSSSelectorReplaces($itemStyles, array("{{current_item}}" => ".elementor-repeater-item-" . $itemId));

			$styles .= $itemStyles;

		}

		return $styles;
	}

	/**
	 * get selectors css
	 */
	public function getSelectorsCss(){

		$style = $this->processPreviewParamsSelectors();

		return $style;
	}

	/**
	 * get addon preview html
	 */
	public function getPreviewHtml(){

		$this->validateInited();

		$outputs = "";

		$title = $this->addon->getTitle();
		$title .= " ". esc_html__("Preview","unlimited-elements-for-elementor");
		$title = htmlspecialchars($title);

		//get libraries, but not process provider
		$htmlBody = $this->getHtmlBody(false);

		$arrIncludes = $this->getProcessedIncludes(true, false);

		$arrIncludes = $this->modifyPreviewIncludes($arrIncludes);

		$htmlInlcudesCss = $this->getHtmlIncludes($arrIncludes,"css");
		$htmlInlcudesJS = $this->getHtmlIncludes($arrIncludes,"js");

		//process selectors only for preview (elementor output uses its own processing)

		$this->processPreviewParamsSelectors();

		$arrCssCustomStyles = UniteProviderFunctionsUC::getCustomStyles();

		$htmlCustomCssStyles = HelperHtmlUC::getHtmlCustomStyles($arrCssCustomStyles);

		$arrJsCustomScripts = UniteProviderFunctionsUC::getCustomScripts();
		$htmlJSScripts = HelperHtmlUC::getHtmlCustomScripts($arrJsCustomScripts);

		$options = $this->addon->getOptions();

		$bgCol = $this->addon->getOption("preview_bgcol");
		$previewSize = $this->addon->getOption("preview_size");

		$previewWidth = "100%";

		switch($previewSize){
			case "column":
				$previewWidth = "300px";
			break;
			case "custom":
				$previewWidth = $this->addon->getOption("preview_custom_width");
				if(!empty($previewWidth)){
					$previewWidth = (int)$previewWidth;
					$previewWidth .= "px";
				}
			break;
		}


		$style = "";
		$style .= "max-width:{$previewWidth};";
		$style .= "background-color:{$bgCol};";

		$urlPreviewCss = GlobalsUC::$urlPlugin."css/unitecreator_preview.css";

		$html = "";
		$htmlHead = "";

		$htmlHead = "<!DOCTYPE html>".self::BR;
		$htmlHead .= "<html>".self::BR;

		//output head
		$htmlHead .= self::TAB."<head>".self::BR;
		$html .= $htmlHead;

		//get head html
		$htmlHead .= self::TAB2."<title>{$title}</title>".self::BR;
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		$htmlHead .= self::TAB2."<link rel='stylesheet' href='{$urlPreviewCss}' type='text/css'>".self::BR;
		$htmlHead .= $htmlInlcudesCss;

		if(!empty($htmlCustomCssStyles))
			$htmlHead .= self::BR.$htmlCustomCssStyles;

		$html .= $htmlHead;
		$output["head"] = $htmlHead;

		$htmlAfterHead = "";
		$htmlAfterHead .= self::TAB."</head>".self::BR;

		//output body
		$htmlAfterHead .= self::TAB."<body>".self::BR;
		$htmlAfterHead .= self::BR.self::TAB2."<div class='uc-preview-wrapper' style='{$style}'>";
		$htmlAfterHead .= self::BR.$htmlBody;
		$htmlAfterHead .= self::BR.self::TAB2."</div>";

		$html .= $htmlAfterHead;
		$output["after_head"] = $htmlAfterHead;

		$htmlEnd = "";
		$htmlEnd .= $htmlInlcudesJS.self::BR;
		$htmlEnd .= $htmlJSScripts.self::BR;

		$htmlEnd .= self::BR.self::TAB."</body>".self::BR;
		$htmlEnd .= "</html>";

		$html .= $htmlEnd;
		$output["end"] = $htmlEnd;

		$output["full_html"] = $html;


		return($output);
	}



	/**
	 * put html preview
	 */
	public function putPreviewHtml(){

		$output = $this->getPreviewHtml();
		uelm_echo($output["head"]);

		//$this->putPreviewHtml_headerAdd();
		uelm_echo($output["after_head"]);

		$this->putPreviewHtml_footerAdd();
		uelm_echo($output["end"]);
	}

	private function a________DYNAMIC___________(){}


	/**
	 * init dynamic params
	 */
	protected function initDynamicParams(){

		$isDynamicAddon = UniteFunctionsUC::getVal($this->arrOptions, "dynamic_addon");
		$isDynamicAddon = UniteFunctionsUC::strToBool($isDynamicAddon);

		if($isDynamicAddon == false)
			return(false);

		$postID = $this->getDynamicPostID();

		if(!empty($postID)){

			$arrPostAdditions = HelperProviderUC::getPostAdditionsArray_fromAddonOptions($this->arrOptions);

			$this->addPostParamToAddon($postID, $arrPostAdditions);
		}

	}


	/**
	 * get post ID
	 */
	protected function getDynamicPostID(){

		$postID = "";

		//get post from preview
		if($this->isModePreview){

			$postID = UniteFunctionsUC::getVal($this->arrOptions, "dynamic_post");

			return($postID);
		}

		//if not preview get the current post

		$post = get_post();

		if(!empty($post))
			$postID = $post->ID;

		return($postID);
	}


	/**
	 * add post param to addon
	 */
	private function addPostParamToAddon($postID, $arrPostAdditions){

		$arrParam = array();
		$arrParam["type"] = UniteCreatorDialogParam::PARAM_POST;
		$arrParam["name"] = "current_post";
		$arrParam["default_value"] = $postID;
		$arrParam["post_additions"] = $arrPostAdditions;


		$this->addon->addParam($arrParam);
	}

	private function ___________DEBUG_DATA___________(){}

	/**
	 * check and output debug if needed
	 */
	public function checkOutputDebug($objAddon = null){

		if(empty($objAddon))
			$objAddon = $this->addon;

		$arrValues = $objAddon->getOriginalValues();

		if(empty($arrValues))
			return(false);

		$isShowData = UniteFunctionsUC::getVal($arrValues, "show_widget_debug_data");

		$isShowData = UniteFunctionsUC::strToBool($isShowData);

		if($isShowData == false)
			return(false);

		if(HelperUC::canShowDebugOutput() == false)
			return(false);

		$dataType = UniteFunctionsUC::getVal($arrValues, "widget_debug_data_type");

		$this->showDebugData($isShowData, $dataType, $arrValues);

	}


	/**
	 * set to show debug data of the addon
	 */
	public function showDebugData($isShow = true, $dataType = null, $arrValues = null){

		if($isShow == true && HelperUC::canShowDebugOutput() == false)
			$isShow = false;

		$this->isShowDebugData = $isShow;
		$this->debugDataType = $dataType;

		$this->valuesForDebug = $arrValues;

	}
	
	/**
	 * get debug html
	 */
	public function getHtmlDebug(){
		
		return($this->htmlDebug);
	}

	/**
	 * put debug data html
	 */
	private function putDebugDataHtml_default($arrData, $arrItemData){
		
		$isShowData = $this->debugDataType != "items_only";
	
		$html = "";

		if($isShowData == true){

			//modify the data
			$arrData = UniteFunctionsUC::modifyDataArrayForShow($arrData);

			$html .= dmpGet($arrData);
		}

		//show settings values

		if($this->debugDataType == "settings_values"){

			$html .= dmpGet("<b>----------- Settings Values -----------</b>");

			$html .= dmpGet($this->valuesForDebug);
		}


		$html .= dmpGet("<b>Widget Items Data</b>");

		if(empty($arrItemData)){
			$html .= dmpGet("no items found");
			return($html);
		}

		$arrItemData = $this->modifyItemsDataForShow($arrItemData);

		$html .= dmpGet($arrItemData);

		return($html);
	}

	/**
	 * modify debug array - output for debug, fordebug
	 */
	private function modifyDebugArray($arrDebug){
		
		if(is_array($arrDebug) == false)
			$arrDebug = (array)$arrDebug;

		if(empty($arrDebug))
			return($arrDebug);

		$output = array();

		foreach($arrDebug as $key => $value){

			if(is_array($value) && count($value) == 1)
				$value = $value[0];

			if(is_string($value) == false)
				continue;

			$value = htmlspecialchars($value);

			if(strlen($value) > 200)
				$value = substr($value, 0, 200)."...";

			$key = " ".$key;

			$output[$key] = $value;
		}


		return($output);
	}


	/**
	 * put debug data - current post
	 */
	private function putDebugDataHTML_currentPostData(){

		$post = get_post();

		if(empty($post)){

			$html = "no current post found";

			return($html);
		}

		$arrPost = $this->modifyDebugArray($post);
		
		$html = dmpGet("<b> ------- Post  ------- </b>");

		$html .= dmpGet($arrPost);

		dmp("<b> ------- Post Meta ------- </b>");

		$meta = get_post_meta($post->ID);

		$meta = $this->modifyDebugArray($meta);

		$html .= dmpGet($meta);

		$html .= dmpGet("<b> ----------Terms--------- </b>");

		$terms = UniteFunctionsWPUC::getPostTermsTitlesString($post, true);
		
		$html .= dmpGet($terms);
		
		return($html);
	}
	
	
	/**
	 * put debug data - posts
	 */
	private function putDebugDataHtml_posts($arrItemData){

		$numPosts = count($arrItemData);

		$html = "";

		$html .= dmpGet("Found $numPosts posts.");

		if(empty($arrItemData))
			return($html);

		$isShowMeta = ($this->debugDataType == "post_meta");

		foreach($arrItemData as $index => $item){

			$isPost = false;
			if($item instanceof WP_Post)
				$isPost = true;

			//check the multisource type
			if($isPost == false){
				
				$itemTest = UniteFunctionsUC::getVal($item, "item");
				
				$objectType = UniteFunctionsUC::getVal($itemTest, "object_type");
				$objectID = UniteFunctionsUC::getVal($itemTest, "object_id");
				
				if(!empty($objectID) && $objectType === "posts"){
					$item = get_post($objectID);
					$isPost = true;					
				}
			}

			
			if($isPost == false){
				
				$item = UniteFunctionsUC::getVal($item, "item");

				$postData = UniteFunctionsUC::getArrFirstValue($item);

				$title = UniteFunctionsUC::getVal($postData, "title");
				$alias = UniteFunctionsUC::getVal($postData, "alias");
				$id = UniteFunctionsUC::getVal($postData, "id");
				$post = get_post($id);

			}else{

				$post = $item;
				$title = $post->post_title;
				$id = $post->ID;
				$alias = $post->post_name;
			}

			$num = $index+1;

			$status = $post->post_status;
			$menuOrder = $post->menu_order;

			$arrTermsNames = UniteFunctionsWPUC::getPostTermsTitles($post, true);

			$strTerms = implode(",", $arrTermsNames);

			$htmlAfterAlias = "";
			if($status != "publish")
				$htmlAfterAlias = ", [$status post]";

			$text = "{$num}. <b>$title</b> (<i style='font-size:13px;'>$alias{$htmlAfterAlias}, $id | $strTerms </i>), menu order: $menuOrder";

			$html .= dmpGet($text);

			if($isShowMeta == false)
				continue;
		
			$html .= ("<b> ------- Post Meta ------- </b>");
			
			$postMeta = get_post_meta($id, "", false);
						
			$postMeta = $this->modifyDebugArray($postMeta);
			
			$html .= dmpGet($postMeta);

			$html .= ("<b> ------- Post Terms ------- </b>");
			
			$terms = UniteFunctionsWPUC::getPostTermsTitlesString($post, true);
			
			$html .= dmpGet($terms);
			
		}


		return($html);

	}

	/**
	 * get items from listing
	 */
	private function putDebugDataHtml_getItemsFromListing($paramListing, $arrData){

		$name = UniteFunctionsUC::getVal($paramListing, "name");

		$source = UniteFunctionsUC::getVal($arrData, $name."_source");

		$arrItemsRaw = UniteFunctionsUC::getVal($arrData, $name."_items");

		if(empty($arrItemsRaw))
			$arrItemsRaw = array();

		$useFor = UniteFunctionsUC::getVal($paramListing, "use_for");
    	$useForGallery = ($useFor == "gallery");


		$arrItems = array();
		foreach($arrItemsRaw as $item){

			if($useForGallery == true && isset($item["postid"])){

				$post = get_post($item["postid"]);
				$arrItems[] = $post;
				continue;
			}

			$object = UniteFunctionsUC::getVal($item, "object");
			$arrItems[] = $object;
		}

		return($arrItems);
	}

	/**
	 * put debug data
	 */
	private function putDebugDataHtml($arrData, $arrItemData){

		$html = "<div class='uc-debug-output' style='font-size:16px;color:black;text-decoration:none;background-color:white;padding:3px;'>";

		$html .= dmpGet("<b>Widget Debug Data</b> (turned on by setting in widget advanced section)<br>",true);

		//get data from listing
		$paramListing = $this->addon->getListingParamForOutput();
		
		if(!empty($paramListing) && $this->itemsType == "template"){

			$arrItemData = $this->putDebugDataHtml_getItemsFromListing($paramListing, $arrData);
		}

		switch($this->debugDataType){
			case "post_titles":
			case "post_meta":
			
				$html .= $this->putDebugDataHtml_posts($arrItemData);
			
			break;
			case "current_post_data":

				$html .= $this->putDebugDataHTML_currentPostData();

			break;
			default:
				$html .= $this->putDebugDataHtml_default($arrData, $arrItemData);
			break;
		}

		$html .= "</div>";

		$this->htmlDebug = $html;
	}

	private function a________GUTENBERG_BACKGROUND_AND_ADDITIONS___________(){}
	
	/**
	 * modify js for gutenberg background output
	 */
	private function modifyGutenbergBGJS($js){
		
		$ucID = $this->generatedID;
		
		$js = "
jQuery(document).ready(function(){ \n
	jQuery(\"#{$ucID}-root\").parent().css({\"position\":\"relative\"});
	jQuery(\"#{$ucID}-root\").addClass(\"uc-background-active\");
	
$js 
}) //onready wrapper;
		";

		return($js);
	}
	
	/**
	 * modify css for gutenberg output
	 */
	private function modifyGutenbergBGCSS($css){
		
				
		
		$ucID = $this->generatedID;
		
		$isInsideEditor = $this->isInsideEditor();
		
		//back css
		
		if($isInsideEditor == true){
			
			$css = ".{$ucID}-root .uc-background-editor-placeholder{
				font-size:12px;
				padding:20px;
				color:black;
			}
			
			.{$ucID}-root{
				position:relative;
				border:1px solid gray;
				background-color:lightgray;
			}
			
			$css";
			
			return($css);
		}
		
		//front css

		$arrValues = $this->addon->getOriginalValues();
		$backgroundLocation = UniteFunctionsUC::getVal($arrValues, "background_location");
		
		$zIndex = -1;
		if($backgroundLocation == "front")
			$zIndex = 999;
		
		$css = "
/* background wrapper */
.{$ucID}-root.uc-background-active{
	position: absolute;
	top:0px;
	left:0px;
	height:100%;
	width:100%;
	z-index:{$zIndex} !important;
}
$css
";
		
	return($css);	
	}
	
	/**
	 * modify gutenberg bg html
	 */
	private function modifyGutenbergBGHTML(){
				
		$html = "";
		
		$isInsideEditor = $this->isInsideEditor();
		
		if($isInsideEditor == false)
			return($html);

		$addonTitle = $this->addon->getTitle();
		
		$addonTitle = esc_html($addonTitle);
		
		$text = __("Background Placeholder for ","unlimited-elements-for-elementor");
		
		$text2 = __("Will cover the parent block in front end.","unlimited-elements-for-elementor");
		
		$html .= "<div class='uc-background-editor-placeholder'>{$text} <b>{$addonTitle}</b>. $text2</div>";
		
		return($html);
	}
	
	/**
	 * add gutenberg global css
	 */
	private function addGutenbergGlobalCss($css){
		
		if(self::$isGutenbergGlobalCssAdded == true)
			return($css);
		
		self::$isGutenbergGlobalCssAdded = true;
		
		$css .= "/* Gutenberg Global */\n";
		$css .= ".ue-widget-root {position:relative;}";
		$css .= 'html .wp-block:has(.ue-widget-root) {margin-top: 0; margin-bottom:0;}';
				
		return($css);
	}
	
	private function a________GENERAL___________(){}


	/**
	 * modify items data for show
	 */
	private function modifyItemsDataForShow($arrItemData){

		if(is_array($arrItemData) == false)
			return(null);

		$arrItemsForShow = array();


		foreach($arrItemData as $item){

			if(is_array($item) == false){
				$arrItemsForShow[] = $item;
				continue;
			}


			$item = UniteFunctionsUC::getVal($item, "item");

			$itemFirstValue = UniteFunctionsUC::getArrFirstValue($item);

			if(is_array($itemFirstValue))
				$item = UniteFunctionsUC::modifyDataArrayForShow($itemFirstValue);
			else
				$item = UniteFunctionsUC::modifyDataArrayForShow($item);

			$arrItemsForShow[] = $item;
		}

		return($arrItemsForShow);
	}




	/**
	 * process html before output, function for override
	 */
	protected function processHtml($html){

		return($html);
	}



	/**
	 * get only processed html template
	 */
	public function getProcessedHtmlTemplate(){

		$html = $this->objTemplate->getRenderedHtml(self::TEMPLATE_HTML);
		$html = $this->processHtml($html);

		return($html);
	}

	/**
	 * check if the widget html uses get_items() loop instead of put_items()
	 */
	private function isHtmlItemsUsesGetItems($html){

		if(empty($html))
			return(false);

		if(stripos($html, "get_items") === false)
			return(false);

		if(stripos($html, "put_items") !== false)
			return(false);

		return(true);
	}


	/**
	 * extract inner html of items wrapper for filters ajax refresh
	 */
	private function extractItemsWrapperHtml($html, $wrapperClass){

		if(empty($html))
			return("");

		if(class_exists("DOMDocument") == false)
			return("");

		libxml_use_internal_errors(true);

		$dom = new DOMDocument();
		$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

		$xpath = new DOMXPath($dom);
		$classToken = " {$wrapperClass} ";
		$query = "//*[contains(concat(' ', normalize-space(@class), ' '), '{$classToken}')]";
		$nodes = $xpath->query($query);

		if(empty($nodes) || $nodes->length == 0){
			libxml_clear_errors();
			return("");
		}

		$node = $nodes->item(0);
		$innerHTML = "";

		foreach($node->childNodes as $child)
			$innerHTML .= $dom->saveHTML($child);

		libxml_clear_errors();

		return($innerHTML);
	}


	/**
	 * get items html from full widget template (for get_items twig loop)
	 */
	private function getHtmlItemsFromGetItemsTemplate(){

		$html = $this->getProcessedHtmlTemplate();

		$output = array();
		$output["html_items1"] = $this->extractItemsWrapperHtml($html, "uc-items-wrapper");
		$output["html_items2"] = $this->extractItemsWrapperHtml($html, "uc-items-wrapper2");

		return($output);
	}


	/**
	 * get items html
	 */
	public function getHtmlItems(){

		$htmlMain = $this->addon->getHtml();

		if(GlobalsProviderUC::$isUnderAjax == true && $this->isHtmlItemsUsesGetItems($htmlMain) == true)
			return($this->getHtmlItemsFromGetItemsTemplate());

		$keyTemplate = "uc_template_items_special";
		$htmlTemplate = "{{put_items()}}";

		$keyTemplate2 = "uc_template_items_special2";
		$htmlTemplate2 = "{{put_items2()}}";

		$this->objTemplate->addTemplate($keyTemplate, $htmlTemplate, false);
		$this->objTemplate->addTemplate($keyTemplate2, $htmlTemplate2, false);

		$html = $this->objTemplate->getRenderedHtml($keyTemplate);
		$html2 = $this->objTemplate->getRenderedHtml($keyTemplate2);

		$html = $this->processHtml($html);
		$html2 = $this->processHtml($html2);

		$output = array();
		$output["html_items1"] = $html;
		$output["html_items2"] = $html2;

		return($output);
	}


	/**
	 * get only html template output, no css and no js
	 */
	public function getHtmlOnly(){

		$this->validateInited();

		$html = $this->objTemplate->getRenderedHtml(self::TEMPLATE_HTML);
		$html = $this->processHtml($html);

		return($html);
	}

	/**
	 * get script handle with serial
	 */
	private function getScriptHandle($handle){

		if(isset(self::$arrScriptsHandles[$handle]) == false){
			self::$arrScriptsHandles[$handle] = true;
			return($handle);
		}

		$counter = 2;

		do{

			$outputHandle = $handle.$counter;

			$isExists = isset(self::$arrScriptsHandles[$outputHandle]);

			$counter++;

		}while($isExists);

			self::$arrScriptsHandles[$outputHandle] = true;

		return($outputHandle);
	}

	/**
	 * place output by shortcode
	 */
	public function getHtmlBody($scriptHardCoded = true, $putCssIncludes = false, $putCssInline = true, $params = null){
		
		$this->validateInited();

		//render the js inside "template" tag always if available
		if(GlobalsProviderUC::$renderJSForHiddenContent == true)
			$scriptHardCoded = true;

		$title = $this->addon->getTitle(true);

		$isOutputComments = HelperProviderCoreUC_EL::getGeneralSetting("output_wrapping_comments");
		$isOutputComments = UniteFunctionsUC::strToBool($isOutputComments);

		try{
			$html = $this->objTemplate->getRenderedHtml(self::TEMPLATE_HTML);
			$html = $this->processHtml($html);
			
			if(!empty($this->htmlDebug))
				$html = $this->htmlDebug . $html;
			
			//make css
			$css = $this->objTemplate->getRenderedHtml(self::TEMPLATE_CSS);
			$js = $this->objTemplate->getRenderedHtml(self::TEMPLATE_JS);
			
			$arrData = $this->getConstantData();

			//gutenberg styles
			
			if($this->isGutenberg == true)
				$css = $this->addGutenbergGlobalCss($css);
			
			if($this->isGutenbergBackground){
				$params["wrap_js_timeout"] = false;
				
				$js = $this->modifyGutenbergBGJS($js);
				
				$css = $this->modifyGutenbergBGCSS($css);
				
			}

			
			//fetch selectors (add google font includes on the way)
			$isAddSelectors = UniteFunctionsUC::getVal($params, "add_selectors_css");
			$isAddSelectors = UniteFunctionsUC::strToBool($isAddSelectors);

			$cssSelectors = "";
			
			if($isAddSelectors === true)
				$cssSelectors = $this->getSelectorsCss();

			//get css includes if needed
			$arrCssIncludes = array();
	
			if($putCssIncludes == true)
				$arrCssIncludes = $this->getProcessedIncludes(true, true, "css");
						
			if($isOutputComments == true)
				$output = "\n<!-- start {$title} -->";
			else
				$output = "\n";
			
			//add css includes if needed
			if(!empty($arrCssIncludes)){
				$htmlIncludes = $this->getHtmlIncludes($arrCssIncludes);

				if(self::$isBufferingCssActive == true)
					self::$bufferCssIncludes .= self::BR . $htmlIncludes;
				else
					$output .= "\n" . $htmlIncludes;
			}
			
			//add css
			if(!empty($css)){
				
				$widgetText = GlobalsProviderUC::$widgetText;
				
				$css = "/* {$widgetText}: $title */" . self::BR2 . $css . self::BR2;

				if(self::$isBufferingCssActive == true){
					//add css to buffer
					if(!empty(self::$bufferBodyCss))
						self::$bufferBodyCss .= self::BR2;

					self::$bufferBodyCss .= $css;
				}else{
					if($putCssInline == true)
						$output .= "\n<style>$css</style>";
					else
						HelperUC::putInlineStyle($css);
				}
			}

			//add css selectors
			if($isAddSelectors == true){
				$selectorsStyleID = "selectors_css_" . $this->generatedID;

				$output .= "\n<style id=\"$selectorsStyleID\" name=\"uc_selectors_css\">$cssSelectors</style>";
			}

			//add html
			
			$addWrapper = false;
			if(GlobalsProviderUC::$renderPlatform == GlobalsProviderUC::RENDER_PLATFORM_GUTENBERG)
				$addWrapper = true;

			if($isAddSelectors == true)
				$addWrapper = true;
			
			
			if($addWrapper == true){

				$id = $this->getWidgetWrapperID();
				
				$rootId = UniteFunctionsUC::getVal($params, "root_id");

				if(empty($rootId) === true)
					$rootId = $id; // $rootId = $this->getWidgetID();
				
                $currentHideDebug = GlobalsUC::$hideDebug;
				GlobalsUC::$hideDebug = true;
                $params = $this->addon->getProcessedMainParamsValues($this->processType);
				GlobalsUC::$hideDebug = $currentHideDebug;
				
                $advancedAddClasses = UniteFunctionsUC::getVal($params, "advanced_css_classes");

				$output .= "\n<div id=\"" . esc_attr($id) . "\" class=\"ue-widget-root " . esc_attr($advancedAddClasses) . " " . esc_attr($id) . "\" data-id=\"" . esc_attr($rootId) . "\">";
			}
			
			
			$output .= "\n\n" . $html;
						
			if($isAddSelectors == true)
				$output .= "\n</div>";
			
			//add background placeholder after the wrapper
			
			if($this->isGutenbergBackground)
				$output .= $this->modifyGutenbergBGHTML();
				
			//add js
			$isOutputJs = false;

			if(!empty($js))
				$isOutputJs = true;

			if(isset($params["wrap_js_start"]) || isset($params["wrap_js_timeout"]))
				$isOutputJs = true;
						
			//output js
			if($isOutputJs == true){
				
				$isJSAsModule = $this->addon->getOption("js_as_module");
				$isJSAsModule = UniteFunctionsUC::strToBool($isJSAsModule);

				$title = $this->addon->getTitle();

				$js = "\n/* $title scripts: */ \n\n" . $js;

				$addonName = $this->addon->getAlias();

				$handle = $this->getScriptHandle("ue_script_" . $addonName);

				if($scriptHardCoded == false){
					UniteProviderFunctionsUC::printCustomScript($js, false, $isJSAsModule, $handle);
				}else{
					$wrapInTimeout = UniteFunctionsUC::getVal($params, "wrap_js_timeout");
					$wrapInTimeout = UniteFunctionsUC::strToBool($wrapInTimeout);

					$wrapStart = UniteFunctionsUC::getVal($params, "wrap_js_start");
					$wrapEnd = UniteFunctionsUC::getVal($params, "wrap_js_end");

					$jsType = "text/javascript";
					if($isJSAsModule == true)
						$jsType = "module";

					$htmlHandle = "";
					if($wrapInTimeout == false)   //add id's in front
						$htmlHandle = " id=\"{$handle}\"";

					$output .= "\n<script type=\"$jsType\" $htmlHandle>";

					if(!empty($wrapStart))
						$output .= "\n" . $wrapStart;

					if($wrapInTimeout == true)
						$output .= "\nsetTimeout(function(){";

					$output .= "\n" . $js;

					if($wrapInTimeout == true)
						$output .= "\n},300);";

					if(!empty($wrapEnd))
						$output .= "\n" . $wrapEnd;

					$output .= "\n</script>";
				}
			}

			if($isOutputComments == true)
				$output .= "\n<!-- end {$title} -->";
		}catch(Exception $e){
			$message = "Widget \"$title\" Error: " . $e->getMessage();

			if(GlobalsUC::$SHOW_TRACE === true){
				dmp($message);
				UniteFunctionsUC::throwError($e);
			}

			UniteFunctionsUC::throwError($message);
		}

		return $output;
	}

	/**
	 * get addon uc_id
	 */
	public function getWidgetID(){

		$data = $this->getConstantData();

		$widgetID = UniteFunctionsUC::getVal($data, "uc_id");

		return($widgetID);
	}

	/**
	 * get widget wrapper id
	 */
	private function getWidgetWrapperID(){

        $params = $this->getAddonParams();

        $cssId = UniteFunctionsUC::getVal($params, 'advanced_css_id');
        if (!empty($cssId)) {
            $cssId = preg_replace('/[^A-Za-z0-9\-_]/', '-', $cssId);
            if (!empty($cssId)) return $cssId;
        }

        $rootId = UniteFunctionsUC::getVal($params, '_rootId');
        if (!empty($rootId)) return $rootId;

		return $this->getWidgetID() . "-root";
	}
	
	/**
	 * get if inside editor or not
	 */
	private function isInsideEditor(){
		
		$data = $this->getConstantData();
		
		$insideEditor = UniteFunctionsUC::getVal($data, "uc_inside_editor");
		
		if($insideEditor == "yes")
			return(true);
		
		return(false);
	}
	
	
	/**
	 * get addon contstant data that will be used in the template
	 */
	public function getConstantData(){

		$this->validateInited();

		if(!empty($this->cacheConstants))
			return($this->cacheConstants);

		$data = array();

		$prefix = "ucid";
		if($this->isInited)
			$prefix = "uc_".$this->addon->getName();

		//add serial number:
		self::$serial++;

		//set output  widget id

		$generatedSerial = self::$serial.UniteFunctionsUC::getRandomString(4, true);

		if(!empty($this->systemOutputID))
			$generatedID = $prefix."_".$this->systemOutputID;
		else
			$generatedID = $prefix.$generatedSerial;

		//protection in listings
		if(isset(self::$arrGeneratedIDs[$generatedID]))
			$generatedID .= self::$serial;

		//double protection
		if(isset(self::$arrGeneratedIDs[$generatedID]))
			$generatedID .= $generatedSerial;


		self::$arrGeneratedIDs[$generatedID] = true;

		$this->generatedID = $generatedID;


		$data["uc_serial"] = $generatedSerial;
		$data["uc_id"] = $this->generatedID;

		//add assets url
		$urlAssets = $this->addon->getUrlAssets();
		if(!empty($urlAssets))
			$data["uc_assets_url"] = $urlAssets;

		//set if it's for editor
		$isInsideEditor = false;
		if($this->processType == UniteCreatorParamsProcessor::PROCESS_TYPE_OUTPUT_BACK)
			$isInsideEditor = true;
		
		//$data["is_inside_editor"] = $isInsideEditor;
		
		$data = UniteProviderFunctionsUC::addSystemConstantData($data);
		
		$data = UniteProviderFunctionsUC::applyFilters(UniteCreatorFilters::FILTER_ADD_ADDON_OUTPUT_CONSTANT_DATA, $data);

		$this->cacheConstants = $data;

		return($data);
	}


	/**
	 * get item extra variables
	 */
	public function getItemConstantDataKeys(){

		$arrKeys = array(
				"item_id",
				"item_index",
				"item_repeater_class",
		);

		return($arrKeys);
	}



	/**
	 * get constant data keys
	 */
	public function getConstantDataKeys($filterPlatformKeys = false){

		$constantData = $this->getConstantData();

		if($filterPlatformKeys == true){
			unset($constantData["uc_platform"]);
			unset($constantData["uc_platform_title"]);
		}

		$arrKeys = array_keys($constantData);

		return($arrKeys);
	}


	/**
	 * get addon params
	 */
	private function getAddonParams(){
		if(!empty($this->paramsCache)) {
			return($this->paramsCache);
        }
        
		$this->paramsCache = $this->addon->getProcessedMainParamsValues($this->processType, true);
        $allParamDefs = $this->addon->getParams();

		return($this->paramsCache);

	}


	/**
	 * modify items data, add "item" to array
	 */
	protected function normalizeItemsData($arrItems, $extraKey=null, $addObjectID = false){

		if(empty($arrItems))
			return(array());

		foreach($arrItems as $key=>$item){

			if(!empty($extraKey)){
				$arrAdd = array($extraKey=>$item);

				//add object id
				if($addObjectID === true){

					$objectID = UniteFunctionsUC::getVal($item, "id");
					if(!empty($objectID))
						$arrAdd["object_id"] = $objectID;

					$postType = UniteFunctionsUC::getVal($item, "post_type");
					if(!empty($postType))
						$arrAdd["object_type"] = $postType;

				}

			}
			else
				$arrAdd = $item;


			$arrItems[$key] = array("item"=>$arrAdd);
		}

		return($arrItems);
	}

	/**
	 * get special items - instagram
	 */
	private function getItemsSpecial_Instagram($arrData){

		$paramInstagram = $this->addon->getParamByType(UniteCreatorDialogParam::PARAM_INSTAGRAM);
		$instaName = UniteFunctionsUC::getVal($paramInstagram, "name");
		$dataInsta = $arrData[$instaName];

		$instaMain = UniteFunctionsUC::getVal($dataInsta, "main");
		$instaItems = UniteFunctionsUC::getVal($dataInsta, "items");
		$error = UniteFunctionsUC::getVal($dataInsta, "error");

		if(empty($instaMain))
			$instaMain = array();

		$instaMain["hasitems"] = !empty($instaItems);

		if(!empty($error))
			$instaMain["error"] = $error;

		$arrItemData = $this->normalizeItemsData($instaItems, $instaName);
		$arrData[$instaName] = $instaMain;

		$output = array();
		$output["main"] = $arrData;
		$output["items"] = $arrItemData;

		return($output);
	}

	/**
	 * get params for modify
	 */
	private function modifyTemplatesForOutput_getParamsForModify(){

		$arrParams = $this->addon->getParams();

		$arrParamsForModify = array();

		foreach($arrParams as $param){

			$type = UniteFunctionsUC::getVal($param, "type");

			if($type != UniteCreatorDialogParam::PARAM_SPECIAL)
				continue;

			$attributeType = UniteFunctionsUC::getVal($param, "attribute_type");

			switch($attributeType){

				case "schema":
				case "entrance_animation":

					$param["modify_type"] = $attributeType;

					$arrParamsForModify[] = $param;
				break;
			}
		}

		return($arrParamsForModify);
	}


	/**
	 * modify template for output, add some code according the params
	 */
	private function modifyTemplatesForOutput($html, $css, $js){

		$isModify = false;

		$arrParams = $this->modifyTemplatesForOutput_getParamsForModify();
		
		$arrValues = $this->addon->getOriginalValues();
		
		$enableSchema = UniteFunctionsUC::getVal($arrValues, "ue_schema_enable");
		$enableSchema = UniteFunctionsUC::strToBool($enableSchema);
		
		//add schema function
		
		if($enableSchema == true){
			
			$html .= "{{ucfunc(\"put_schema_items_json_global\")}}\n\n";
			$isModify = true;
		}
		
		if(empty($arrParams))
			$arrParams = array();
		
		foreach($arrParams as $param){

			$name = UniteFunctionsUC::getVal($param, "name");
			$type = UniteFunctionsUC::getVal($param, "modify_type");

			switch($type){
				case "entrance_animation":

					$css = "{{ucfunc(\"put_entrance_animation_css\",\"{$name}\")}}\n\n".$css;
					$js = "{{ucfunc(\"put_entrance_animation_js\",\"{$name}\")}}\n\n".$js;

					$isModify = true;
				break;
				case "schema":
					
					$html .= "{{ucfunc(\"put_schema_items_json_byparam\",\"{$name}\")}}\n\n";

					$isModify = true;
				break;
			}

		}

		if($isModify == false)
			return(null);


		$output = array();
		$output["html"] = $html;
		$output["css"] = $css;
		$output["js"] = $js;

		return($output);
	}


	/**
	 * init the template
	 */
	private function initTemplate(){

		$this->validateInited();

		//set params
		$arrData = $this->getConstantData();
		$arrParams = $this->getAddonParams();

		$arrData = array_merge($arrData, $arrParams);

		//set templates
		$html = $this->addon->getHtml();
		$css = $this->addon->getCss();

		//set item css call
		$cssItem = $this->addon->getCssItem();
		$cssItem = trim($cssItem);

		if(!empty($cssItem))
			$css .= "\n{{put_css_items()}}";

		$js = $this->addon->getJs();

		$arrModify = $this->modifyTemplatesForOutput($html, $css, $js);

		if(!empty($arrModify)){
			$html = $arrModify["html"];
			$css = $arrModify["css"];
			$js = $arrModify["js"];
		}

		$this->objTemplate->setAddon($this->addon);

		$this->objTemplate->addTemplate(self::TEMPLATE_CSS_ITEM, $cssItem);
		$this->objTemplate->addTemplate(self::TEMPLATE_HTML, $html);
		$this->objTemplate->addTemplate(self::TEMPLATE_CSS, $css);
		$this->objTemplate->addTemplate(self::TEMPLATE_JS, $js);

		//add custom templates
		$arrCustomTemplates = array();
		$arrCustomTemplates = apply_filters("uelm_get_twig_templates", $arrCustomTemplates);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy hook name kept for existing callbacks.
		$arrCustomTemplates = apply_filters("ue_get_twig_templates", $arrCustomTemplates);

		if(!empty($arrCustomTemplates)){
			foreach($arrCustomTemplates as $templateName => $templateValue){
				$this->objTemplate->addTemplate($templateName, $templateValue);
			}
		}

		$arrItemData = null;
		$paramPostsList = null;
		$itemsSource = null;    //from what object the items came from

		//set items template
		if($this->isItemsExists === false){
			$this->objTemplate->setParams($arrData);
		}else{
			if($this->processType == UniteCreatorParamsProcessor::PROCESS_TYPE_CONFIG)
				$arrItemData = array();
			else
				switch($this->itemsType){
					case "instagram":
						$response = $this->getItemsSpecial_Instagram($arrData);
						$arrData = $response["main"];
						$arrItemData = $response["items"];
					break;
					case "post":    //move posts data from main to items
						$paramPostsList = $this->addon->getParamByType(UniteCreatorDialogParam::PARAM_POSTS_LIST);

						if(empty($paramPostsList))
							UniteFunctionsUC::throwError("Some posts list param should be found");

						$postsListName = UniteFunctionsUC::getVal($paramPostsList, "name");
						$arrItemData = $this->normalizeItemsData($arrData[$postsListName], $postsListName, true);

						//set main param (true/false)
						$arrData[$postsListName] = !empty($arrItemData);

						$itemsSource = "posts";
					break;
					case UniteCreatorAddon::ITEMS_TYPE_DATASET:
						$paramDataset = $this->addon->getParamByType(UniteCreatorDialogParam::PARAM_DATASET);

						if(empty($paramDataset))
							UniteFunctionsUC::throwError("Dataset param not found");

						$datasetType = UniteFunctionsUC::getVal($paramDataset, "dataset_type");
						$datasetQuery = UniteFunctionsUC::getVal($paramDataset, "dataset_{$datasetType}_query");

						$arrRecords = array();
						$arrItemData = UniteProviderFunctionsUC::applyFilters(UniteCreatorFilters::FILTER_GET_DATASET_RECORDS, $arrRecords, $datasetType, $datasetQuery);

						if(!empty($arrItemData)){
							$paramName = $paramDataset["name"];
							$arrItemData = $this->normalizeItemsData($arrItemData, $paramName);
						}
					break;
					case "listing":
						$paramListing = $this->addon->getListingParamForOutput();

						if(empty($paramListing))
							UniteFunctionsUC::throwError("Some listing param should be found");

						$paramName = UniteFunctionsUC::getVal($paramListing, "name");
						$arrItemData = UniteFunctionsUC::getVal($arrData, $paramName . "_items");

						if(empty($arrItemData))
							$arrItemData = array();
						else
							$arrItemData = $this->normalizeItemsData($arrItemData, $paramName);
					break;
					case "multisource":
						$paramListing = $this->addon->getListingParamForOutput();

						if(empty($paramListing))
							UniteFunctionsUC::throwError("Some multisource dynamic attribute should be found");

						$paramName = UniteFunctionsUC::getVal($paramListing, "name");
						$dataValue = UniteFunctionsUC::getVal($arrData, $paramName);

						if(is_string($dataValue) && $dataValue === "uc_items"){
                            $arrItemData = $this->getProcessedItemsData();
						}elseif(is_array($dataValue)){
							$arrItemData = $dataValue;
						}else{
							dmp($arrItemData);
							UniteFunctionsUC::throwError("Wrong multisouce data");
						}

						UniteCreatetorParamsProcessorMultisource::checkShowItemsDebug($arrItemData);
					break;
					default:
                        $arrItemData = $this->getProcessedItemsData();
					break;
				}

			//some small protection
			if(empty($arrItemData))
				$arrItemData = array();

			$itemIndex = 0;

			foreach($arrItemData as $key => $item){
								
				$itemIndex++;

				$arrItem = $item["item"];
				$arrItem["item_index"] = $itemIndex;
				$arrItem["item_id"] = $this->generatedID . "_item" . $itemIndex;
				
				$arrItemData[$key]["item"] = $arrItem;
			}

			$this->objTemplate->setParams($arrData);
			$this->objTemplate->setArrItems($arrItemData);

			if(!empty($itemsSource))
				$this->objTemplate->setItemsSource($itemsSource);

			$htmlItem = $this->addon->getHtmlItem();
			$htmlItem2 = $this->addon->getHtmlItem2();

			$this->objTemplate->addTemplate(self::TEMPLATE_HTML_ITEM, $htmlItem);
			$this->objTemplate->addTemplate(self::TEMPLATE_HTML_ITEM2, $htmlItem2);
		}

		if(!empty($paramPostsList)){
			$postListValue = UniteFunctionsUC::getVal($paramPostsList, "value");

			if(!empty($paramPostsList) && is_array($postListValue))
				$arrData = array_merge($arrData, $postListValue);
		}

		if($this->isShowDebugData === true)
			$this->putDebugDataHtml($arrData, $arrItemData);
		
	}


	/**
	 * preview addon mode
	 * dynamic addon should work from the settings
	 */
	public function setPreviewAddonMode(){

		$this->isModePreview = true;
	}

	/**
	 * set system output id for the generated id
	 */
	public function setSystemOutputID($systemID){

		$this->systemOutputID = $systemID;
	}


	/**
	 * init by addon
	 */
	public function initByAddon(UniteCreatorAddon $addon){

		if(empty($addon))
			UniteFunctionsUC::throwError("Wrong addon given");

		//debug data
		HelperUC::clearDebug();

		$this->isInited = true;

		$this->addon = $addon;
		$this->isItemsExists = $this->addon->isHasItems();

		$this->itemsType = $this->addon->getItemsType();

		$this->arrOptions = $this->addon->getOptions();
		
		$typeName = $this->addon->getType();
		
		if($typeName == GlobalsUC::ADDON_TYPE_BGADDON)
			$this->isBackground = true;
				
		if($this->isBackground == true && $this->isGutenberg == true)
			$this->isGutenbergBackground = true;
					
		//modify by special type
		
		switch($this->itemsType){
			case "instagram":
			case "post":
			case "listing":
			case "multisource":
				$this->isItemsExists = true;
			break;
		}

		$this->initDynamicParams();

		$this->initTemplate();

	}


}
