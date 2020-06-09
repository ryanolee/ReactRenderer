<?php

namespace Limenius\ReactRenderer\Twig;

use Psr\Cache\CacheItemPoolInterface;
use Limenius\ReactRenderer\Renderer\AbstractReactRenderer;
use Limenius\ReactRenderer\Context\ContextProviderInterface;

class ReactRenderExtension extends \Twig_Extension
{
    protected $renderServerSide = false;
    protected $renderClientSide = false;
    protected $registeredStores = array();
    protected $needsToSetRailsContext = true;

    private $renderer;
    private $staticRenderer;
    private $contextProvider;
    private $trace;
    private $buffer;
    private $cache;

    /**
     * @param AbstractReactRenderer $renderer
     * @param ContextProviderInterface $contextProvider
     * @param string $defaultRendering
     * @param boolean $trace
     */
    public function __construct(AbstractReactRenderer $renderer = null, ContextProviderInterface $contextProvider, $defaultRendering, $trace = false)
    {
        $this->renderer = $renderer;
        $this->contextProvider = $contextProvider;
        $this->trace = $trace;
        $this->buffer = array();

        switch ($defaultRendering) {
            case 'server_side':
                $this->renderClientSide = false;
                $this->renderServerSide = true;
                break;
            case 'client_side':
                $this->renderClientSide = true;
                $this->renderServerSide = false;
                break;
            case 'both':
                $this->renderClientSide = true;
                $this->renderServerSide = true;
                break;
        }
    }

    /**
     * @param CacheItemPoolInterface $cache
     */
    public function setCache(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @return array
     */
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('react_component', array($this, 'reactRenderComponent'), array('is_safe' => array('html'))),
            new \Twig_SimpleFunction('react_component_array', array($this, 'reactRenderComponentArray'), array('is_safe' => array('html'))),
            new \Twig_SimpleFunction('redux_store', array($this, 'reactReduxStore'), array('is_safe' => array('html'))),
            new \Twig_SimpleFunction('react_flush_buffer', array($this, 'reactFlushBuffer'), array('is_safe' => array('html'))),
        );
    }

    /**
     * @param string $componentName
     * @param array $options
     * @return array
     */
    public function reactRenderComponentArray($componentName, array $options = array())
    {
        $props = isset($options['props']) ? $options['props'] : array();
        $propsArray = is_array($props) ? $props : $this->jsonDecode($props);

        $str = '';
        $data = array(
            'component_name' => $componentName,
            'props' => $propsArray,
            'dom_id' => 'sfreact-'.uniqid('reactRenderer', true),
            'trace' => $this->shouldTrace($options),
        );

        if ($this->shouldRenderClientSide($options)) {
            $tmpData = $this->renderContext();
            $tmpData .= sprintf(
                '<script type="application/json" class="js-react-on-rails-component" data-component-name="%s" data-dom-id="%s">%s</script>',
                $data['component_name'],
                $data['dom_id'],
                $this->jsonEncode($data['props'])
            );
            if ($this->shouldBuffer($options) === true) {
                $this->buffer[] = $tmpData;
            } else {
                $str .= $tmpData;
            }
        }
        $str .= '<div id="'.$data['dom_id'].'">';

        if ($this->shouldRenderServerSide($options)) {
            $rendered = $this->serverSideRender($data, $options);
            if ($rendered['hasErrors']) {
                $str .= $rendered['evaluated'].$rendered['consoleReplay'];
            } else {
                $evaluated = $rendered['evaluated'];
                $str .= $evaluated['componentHtml'].$rendered['consoleReplay'];
            }
        }
        $str .= '</div>';

        $evaluated['componentHtml'] = $str;

        return $evaluated;
    }

    /**
     * @param string $componentName
     * @param array $options
     * @return string
     */
    public function reactRenderComponentArrayStatic($componentName, array $options = array())
    {
        $renderer = $this->renderer;
        $this->renderer = $this->staticRenderer;

        $rendered = $this->reactRenderComponentArray($componentName, $options);
        $this->renderer = $renderer;

        return $rendered;
    }

    /**
     * @param string $componentName
     * @param array $options
     * @return string
     */
    public function reactRenderComponent($componentName, array $options = array())
    {
        $props = isset($options['props']) ? $options['props'] : array();
        $propsArray = is_array($props) ? $props : $this->jsonDecode($props);

        $str = '';
        $data = array(
            'component_name' => $componentName,
            'props' => $propsArray,
            'dom_id' => 'sfreact-'.uniqid('reactRenderer', true),
            'trace' => $this->shouldTrace($options),
        );

        if ($this->shouldRenderClientSide($options)) {
            $tmpData = $this->renderContext();
            $tmpData .= sprintf(
                '<script type="application/json" class="js-react-on-rails-component" data-component-name="%s" data-dom-id="%s">%s</script>',
                $data['component_name'],
                $data['dom_id'],
                $this->jsonEncode($data['props'])
            );
            if ($this->shouldBuffer($options) === true) {
                $this->buffer[] = $tmpData;
            } else {
                $str .= $tmpData;
            }
        }
        $str .= '<div id="'.$data['dom_id'].'">';
        if ($this->shouldRenderServerSide($options)) {
            $rendered = $this->serverSideRender($data, $options);
            $evaluated = $rendered['evaluated'];
            $str .= $rendered['evaluated'].$rendered['consoleReplay'];
        }
        $str .= '</div>';

        return $str;
    }

    /**
     * @param string $componentName
     * @param array $options
     * @return string
     */
    public function reactRenderComponentStatic($componentName, array $options = array())
    {
        $renderer = $this->renderer;
        $this->renderer = $this->staticRenderer;

        $rendered = $this->reactRenderComponent($componentName, $options);
        $this->renderer = $renderer;

        return $rendered;
    }

    /**
     * @param string $storeName
     * @param array|string $props
     * @return string
     */
    public function reactReduxStore($storeName, $props)
    {
        $propsString = is_array($props) ? $this->jsonEncode($props) : $props;
        $this->registeredStores[$storeName] = $propsString;

        $reduxStoreTag = sprintf(
            '<script type="application/json" data-js-react-on-rails-store="%s">%s</script>',
            $storeName,
            $propsString
        );

        return $this->renderContext().$reduxStoreTag;
    }

    /**
     * @return string
     */
    public function reactFlushBuffer()
    {
        $str = '';

        foreach ($this->buffer as $item) {
            $str .= $item;
        }

        $this->buffer = array();

        return $str;
    }

    /**
     * @param array $options
     * @return boolean
     */
    public function shouldRenderServerSide(array $options)
    {
        if (isset($options['rendering'])) {
            if (in_array($options['rendering'], ['server_side', 'both'], true)) {
                return true;
            } else {
                return false;
            }
        }

        return $this->renderServerSide;
    }

    /**
     * @param array $options
     * @return string
     */
    public function shouldRenderClientSide(array $options)
    {
        if (isset($options['rendering'])) {
            if (in_array($options['rendering'], ['client_side', 'both'], true)) {
                return true;
            } else {
                return false;
            }
        }

        return $this->renderClientSide;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'react_render_extension';
    }

    /**
     * @param array $options
     * @return boolean
     */
    protected function shouldTrace(array $options)
    {
        return isset($options['trace']) ? $options['trace'] : $this->trace;
    }

    /**
     * @return string
     */
    private function renderContext()
    {
        if ($this->needsToSetRailsContext) {
            $this->needsToSetRailsContext = false;

            return sprintf(
                '<script type="application/json" id="js-react-on-rails-context">%s</script>',
                $this->jsonEncode($this->contextProvider->getContext(false))
            );
        }

        return '';
    }

    /**
     * @param array $input
     * @return string
     */
    private function jsonEncode($input)
    {
        $json = json_encode($input);

        if (json_last_error() !== 0) {
            throw new \Limenius\ReactRenderer\Exception\PropsEncodeException(
                sprintf(
                    'JSON could not be encoded, Error Message was %s',
                    json_last_error_msg()
                )
            );
        }

        return $json;
    }

    /**
     * @param string $input
     * @return array
     */
    private function jsonDecode($input)
    {
        $json = json_decode($input);

        if (json_last_error() !== 0) {
            throw new \Limenius\ReactRenderer\Exception\PropsDecodeException(
                sprintf(
                    'JSON could not be decoded, Error Message was %s',
                    json_last_error_msg()
                )
            );
        }

        return $json;
    }

    /**
     * @param array $data
     * @param array $options
     * @return array
     */
    private function serverSideRender(array $data, array $options)
    {
        if ($this->shouldCache($options)) {
            return $this->renderCached($data, $options);
        } else {
            return $this->doServerSideRender($data);
        }
    }

    /**
     * @param array $data
     * @return array
     */
    private function doServerSideRender($data)
    {
        return $this->renderer->render(
            $data['component_name'],
            json_encode($data['props']),
            $data['dom_id'],
            $this->registeredStores,
            $data['trace']
        );
    }

    /**
     * @param array|null $data
     * @param array $options
     * @return array
     */
    private function renderCached($data, $options)
    {
        if ($this->cache === null) {
            return $this->doServerSideRender($data);
        }

        $cacheItem = $this->cache->getItem($data['component_name'].$this->getCacheKey($options, $data));
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $rendered = $this->doServerSideRender($data);

        $cacheItem->set($rendered);
        $this->cache->save($cacheItem);

        return $rendered;
    }

    /**
     * @param array $options
     * @param array $data
     * @return string
     */
    private function getCacheKey($options, $data)
    {
        return isset($options['cache_key']) && $options['cache_key'] ? $options['cache_key'] : $data['component_name'].'.rendered';
    }

    /**
     * @param array $options
     * @return boolean
     */
    private function shouldCache($options)
    {
        return isset($options['cached']) && $options['cached'];
    }

    /**
     * @param array $options
     * @return boolean
     */
    private function shouldBuffer($options)
    {
        return isset($options['buffered']) && $options['buffered'];
    }
}
