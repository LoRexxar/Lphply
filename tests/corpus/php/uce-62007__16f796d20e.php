<?php
/*
* Plugin Name: Unlimited Elements for Elementor
* Plugin URI: http://unlimited-elements.com
* Description: Elementor all-in-one addons pack with the best widgets for Elementor, offering 100+ free widgets, templates, and tools to create stunning websites!
* Author: Unlimited Elements
* Version: 2.0.26
* Author URI: http://unlimited-elements.com
* Text Domain: unlimited-elements-for-elementor
* Domain Path: /languages
* Requires PHP: 7.4
* Requires at least: 5.7
*
* Tested up to: 7.1
* Elementor tested up to: 4.2.4
* Elementor Pro tested up to: 4.2.2
* 
* License: GPLv2 or later
* License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/
if ( ! defined( 'ABSPATH' ) ) exit;


if(!defined("UNLIMITED_ELEMENTS_INC"))
	define("UNLIMITED_ELEMENTS_INC", true);

// -------------------

if(!defined("UE_ENABLE_ELEMENTOR_SUPPORT"))
	define("UE_ENABLE_ELEMENTOR_SUPPORT", true);
else{
	if(!defined("UC_BOTH_VERSIONS_ACTIVE"))
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared with the other Unlimited Elements edition.
		define("UC_BOTH_VERSIONS_ACTIVE", true);
} 


/*** Freemius ***/	
if ( ! function_exists( 'uelm_fs' ) ) {
    // Create a helper function for easy SDK access.
    function uelm_fs() {
        global $uelm_fs;

        if ( ! isset( $uelm_fs ) ) {
            // Include Freemius SDK.
            require_once dirname(__FILE__) . '/provider/freemius/start.php';
	
            $uelm_fs = fs_dynamic_init( array(
                'id'                  => '4036',
                'slug'                => 'unlimited-elements-for-elementor',
				'premium_slug'        => 'unlimited-elements-pro',            
                'type'                => 'plugin',
                'public_key'          => 'pk_719fa791fb45bf1896e3916eca491',
                'is_premium'          => false,
				'premium_suffix'      => '(Pro)',            
            	'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'has_affiliation'     => false,
                'menu'                => array(
                    'slug'           => 'unlimitedelements',
                    'support'        => false,
					'affiliation'    => false,            
					'contact'    => false            
                )
            ) );
        }

        return $uelm_fs;
    }

    // Init Freemius.
    uelm_fs();
    // Signal that SDK was initiated.
    do_action( 'uelm_fs_loaded' );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Legacy Freemius hook kept for existing callbacks.
    do_action( 'uefe_fs_loaded' );
    
}	
/*** End Freemius ***/	
	
$uelm_mainFilepath = __FILE__;
$uelm_currentFolder = dirname($uelm_mainFilepath);
$uelm_pathProvider = $uelm_currentFolder."/provider/";


try{
	if(!class_exists("GlobalsUC")) {
		$uelm_pathAltLoader = $uelm_pathProvider."provider_alt_loader.php";
		if(file_exists($uelm_pathAltLoader)){
			
			require $uelm_pathAltLoader;
		
		}else{
			require_once $uelm_currentFolder.'/includes.php';
			
			require_once  GlobalsUC::$pathProvider."core/provider_main_file.php";
		}
		
	}
    

	
}catch(Exception $e){
	$uelm_message = $e->getMessage();
	$uelm_trace = $e->getTraceAsString();
	
	echo "<br>";
	echo esc_html($uelm_message);
	echo "<pre>";
	echo esc_html($uelm_trace);
}

