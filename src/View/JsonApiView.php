<?php
namespace JsonApi\View;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Event\EventManager;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\ORM\Exception\MissingEntityException;
use Cake\Routing\Router;
use Cake\View\View;
use Neomerx\JsonApi\Encoder\Encoder;
use Neomerx\JsonApi\Encoder\EncoderOptions;
use Neomerx\JsonApi\Parameters\EncodingParameters;

class JsonApiView extends View
{
    /**
     * [$_prefixUrl description]
     * @var null
     */
    protected $_prefixUrl = null;

    /**
     * [$_schemas description]
     * @var array
     */
    protected $_schemas = [];

    /**
     * Hold global meta data
     * @var array
     */
    protected $_meta = [];

    /**
     * Constructor
     *
     * @param \Cake\Network\Request $request Request instance.
     * @param \Cake\Network\Response $response Response instance.
     * @param \Cake\Event\EventManager $eventManager EventManager instance.
     * @param array $viewOptions An array of view options
     */
    public function __construct(
        Request $request = null,
        Response $response = null,
        EventManager $eventManager = null,
        array $viewOptions = []
    ) {
        parent::__construct($request, $response, $eventManager, $viewOptions);

        if ($response && $response instanceof Response) {
            $response->type('jsonapi');
        }

        // configure the json-api schema mapping
        if (isset($viewOptions['entities'])) {
            $this->entitiesToSchema($viewOptions['entities']);
        }

        // set the base url for the api
        if (isset($viewOptions['url'])) {
            $this->_prefixUrl = $viewOptions['url'];
        }

        // add metadata to the api response
        if (isset($viewOptions['meta'])) {
            $this->_meta = $viewOptions['meta'];
        }
    }

    /**
     * Map entities to schema files
     * @param  array  $entities An array of entity names that need to be mapped to a schema class
     *   If the schema class does not exist, the default EntitySchema will be used.
     * @return void
     */
    protected function entitiesToSchema(array $entities)
    {
        foreach ($entities as $entity => $options) {

            $entityClassName = $entity;
            if (is_string($options)) {
                $entityClassName = $options;
            }

            $entityclass = App::className($entityClassName, 'Model\Entity');

            if (!$entityclass) {
                throw new MissingEntityException([$entityClassName]);
            }

            $schemaClass = App::className($entityClassName, 'Schema', 'Schema');

            if (!$schemaClass) {
                $schemaClass = App::className('JsonApi.Entity', 'Schema', 'Schema');
            }

            $schema = function ($factory, $container) use ($schemaClass, $entityClassName) {
                return new $schemaClass($factory, $container, $this, $entityClassName);
            };

            $this->_schemas[$entityclass] = $schema;
        }
    }

    /**
     * Render view template or return serialized data.
     *
     * ### Special parameters
     * `_serialize` To convert a set of view variables into a serialized form.
     *   Its value can be a string for single variable name or array for multiple
     *   names. If true all view variables will be serialized. If unset normal
     *   view template will be rendered.
     *
     * @param string|null $view The view being rendered.
     * @param string|null $layout The layout being rendered.
     * @return string|null The rendered view.
     */
    public function render($view = null, $layout = null)
    {
        $serialize = false;
        if (isset($this->viewVars['_serialize'])) {
            $serialize = $this->viewVars['_serialize'];
        }

        return $this->_serialize($serialize);
    }

    /**
     * Serialize view vars
     *
     * ### Special parameters
     * `_serialize` This holds the actual data to pass to the encoder
     * `_include` An array of hash paths of what should be in the 'included' section of the response
     *   see: http://jsonapi.org/format/#fetching-includes
     *   eg: [ 'posts.author' ]
     * `_fieldsets` A hash path of fields a list of names that should be in the resultset
     *   eg: [ 'sites'  => ['name'], 'people' => ['first_name'] ]
     *
     * @param mixed $serialize The data that needs to be encoded using the JsonApi encoder
     * @return string The serialized data
     */
    protected function _serialize($serialize)
    {
        $jsonOptions = $this->_jsonOptions();

        $encoderOptions = new EncoderOptions($jsonOptions, rtrim($this->_prefixUrl, '/'));
        $encoder = Encoder::instance($this->_schemas, $encoderOptions);

        $parameters = $include = $fieldsets = null;
        if (isset($this->viewVars['_include'])) {
            $include = $this->viewVars['_include'];
        }

        if (isset($this->viewVars['_fieldsets'])) {
            $fieldsets = $this->viewVars['_fieldsets'];
        }

        $meta = $this->_meta;
        if (isset($this->viewVars['_meta'])) {
            $meta = array_merge($this->_meta, $this->viewVars['_meta']);
        }

        if ($meta) {
            $encoder->withMeta($meta);
        }

        if (empty($serialize)) {
            return $encoder->encodeMeta($meta);
        }

        $parameters = new EncodingParameters($include, $fieldsets);

        return $encoder->encodeData($serialize, $parameters);
    }

    /**
     * Return json options
     *
     * ### Special parameters
     * `_jsonOptions` You can set custom options for json_encode() this way,
     *   e.g. `JSON_HEX_TAG | JSON_HEX_APOS`.
     *
     * @return int json option constant
     */
    protected function _jsonOptions()
    {
        $jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        if (isset($this->viewVars['_jsonOptions'])) {
            if ($this->viewVars['_jsonOptions'] === false) {
                $jsonOptions = 0;
            } else {
                $jsonOptions = $this->viewVars['_jsonOptions'];
            }
        }

        if (Configure::read('debug')) {
            $jsonOptions = $jsonOptions | JSON_PRETTY_PRINT;
        }

        return $jsonOptions;
    }
}
