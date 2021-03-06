<?php


/**
 * An authentication processing filter that modifies the list of attributes sent to a service depending on the entity
 * categories it belongs to. This filter DOES NOT alter the list of attributes sent itself, but modifies the list of
 * attributes requested by the service provider. Therefore, in order to be of any use, it must be used together with the
 * core:AttributeLimit authentication processing filter.
 *
 * @author Jaime Pérez Crespo, UNINETT AS <jaime.perez@uninett.no>
 * @package SimpleSAMLphp
 */
class sspmod_entitycategories_Auth_Process_EntityCategory extends SimpleSAML_Auth_ProcessingFilter
{

    /**
     * A list of categories available. An associative array where the identifier of the category is the key, and the
     * associated value is an array with all the attributes allowed for services in that category.
     *
     * @var array
     */
    protected $categories = array();

    /**
     * Whether the attributes allowed by this category should be sent by default in case no attributes are explicitly
     * requested or not.
     *
     * @var bool
     */
    protected $default = false;

    private $map = array();
    protected $crtattr = array();
    private $filemap = 'name2oid';

    /**
     * EntityCategory constructor.
     *
     * @param array $config An array with the configuration for this processing filter.
     * @param mixed $reserved For future use.
     * @throws \SimpleSAML\Error\ConfigurationError In case of a misconfiguration of the filter.
     */
    public function __construct($config, $reserved)
    {
        parent::__construct($config, $reserved);

	foreach($config as $index => $value) {
	    if($index === 'map') {
		$this->filemap = $value;
		continue;
	    }
	}
	$this->loadMapFile($this->filemap);
	
        foreach ($config as $index => $value) {
            if ($index === 'default') {
                if (!is_bool($value)) {
		    throw new SimpleSAML_Error_Exception(
                        "The 'default' configuration option must have a boolean value."
                    );
                }
                $this->default = $value;
                continue;
            }
	    
	    if ($index === 'map') continue;
	    
            if (is_numeric($index)) {
		throw new SimpleSAML_Error_Exception(
                    "Unspecified allowed attributes for the '$value' category."
                );
            }

            if (!is_array($value)) {
		throw new SimpleSAML_Error_Exception(
                    "The list of allowed attributes for category '$index' is not an array."
                );
            }

	    foreach($value as $attr) {
		array_push($this->crtattr, $this->map[$attr]);		
	    }

            $this->categories[$index] = $this->crtattr;
        }
    }

    private function loadMapFile($omap){
	 $config = SimpleSAML_Configuration::getInstance();
         $filePath = $config->getPathValue('attributenamemapdir', 'attributemap/') . $omap. '.php';

         if(!file_exists($filePath)) {
             throw new Exception('Could not find attributemap file: ' . $filePath);
         }

         $attributemap = NULL;
         include($filePath);
         if(!is_array($attributemap)) {
             throw new Exception('Attribute map file "' . $filePath . '" didn\'t define an attribute map.');
         }

         $this->map = array_merge($this->map, $attributemap);
    }



    /**
     * Apply the filter to modify the list of attributes for the current service provider.
     *
     * @param array $request The current request.
     */
    public function process(&$request)
    {
        if (!array_key_exists('EntityAttributes', $request['Destination'])) {
            // something weird going on, but abort anyway
            return;
        }

        if (!array_key_exists('http://macedir.org/entity-category', $request['Destination']['EntityAttributes'])) {
            // there's entity attributes, but no entity categories, do nothing
            return;
        }

        $categories = $request['Destination']['EntityAttributes']['http://macedir.org/entity-category'];

        if (!in_array('http://refeds.org/category/research-and-scholarship', $categories)){
            // there's no r&s category, do nothing
            return;
        }

	//var_dump($this->categories);
	//var_dump($categories);
	//var_dump($request['Destination']['attributes']);

	//don't have required or optional attributes in the metadata
        if (!array_key_exists('attributes', $request['Destination'])) {
            if ($this->default) {
                // handle the case of service providers requesting no attributes and the filter being the default policy
                $request['Destination']['attributes'] = array();
                foreach ($categories as $category) {

                    if (!array_key_exists($category, $this->categories)) {
                        continue;
                    }
	
                    $request['Destination']['attributes'] = array_merge(
                        $request['Destination']['attributes'],
                        $this->categories[$category]
                    );
                }
            }
            return;
        }

        // iterate over the requested attributes and see if any of the categories allows them
        foreach ($request['Destination']['attributes'] as $index => $value) {
            $attrname = $value;
            if (!is_numeric($index)) {
                $attrname = $index;
            }

            $found = false;
            foreach ($categories as $category) {
		
                if (!array_key_exists($category, $this->categories)) {
                    continue;
                }

                if (in_array($attrname, $this->categories[$category])) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // no category (if any) allows the attribute, so remove it
                unset($request['Destination']['attributes'][$index]);
            }
        } 
    }
}
