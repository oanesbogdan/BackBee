<?php
namespace BackBuilder\DependencyInjection;

/*
 * Copyright (c) 2011-2013 Lp digital system
 *
 * This file is part of BackBuilder5.
 *
 * BackBuilder5 is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BackBuilder5 is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BackBuilder5. If not, see <http://www.gnu.org/licenses/>.
 */

use BackBuilder\Event\Event;
use BackBuilder\DependencyInjection\ContainerInterface;

use Symfony\Component\DependencyInjection\ContainerBuilder as sfContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as sfContainerInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Extended Symfony Dependency injection component
 *
 * @category    BackBuilder
 * @package     BackBuilder\DependencyInjection
 * @copyright   Lp digital system
 * @author      e.chau <eric.chau@lp-digital.fr>
 */
class Container extends sfContainerBuilder implements ContainerInterface
{
    /**
     * Change current method default behavior: if we try to get a synthetic service it will return
     * null instead of throwing an exception;
     *
     * @see Symfony\Component\DependencyInjection\ContainerBuilder::get()
     */
    public function get($id, $invalid_behavior = sfContainerInterface::EXCEPTION_ON_INVALID_REFERENCE)
    {
        $service = null;
        try {
            $service = parent::get($id, $invalid_behavior);
        } catch (RuntimeException $e) {
            if (false === $this->hasDefinition($id)) {
                throw $e;
            }

            if (false === $this->getDefinition($id)->isSynthetic()) {
                throw $e;
            }
        }

        if (true === in_array('event.dispatcher', array_keys($this->services))) {
            if (null !== $service && true === $this->hasDefinition($id)) {
                $definition = $this->getDefinition($id);
                if (0 < count($tags = $definition->getTags())) {
                    foreach ($tags as $tag => $datas) {
                        $this->services['event.dispatcher']->dispatch(
                            'service.tagged.' . $tag,
                            new Event($service)
                        );
                    }
                }
            }
        }

        return $service;
    }

    /**
     * Giving a string, try to return the container service or parameter if exists
     * This method can be call by array_walk or array_walk_recursive
     * @param mixed $item
     * @return mixed
     */
    public function getContainerValues(&$item)
    {
        if (false === is_object($item) && false === is_array($item)) {
            $item = $this->_getContainerServices($this->_getContainerParameters($item));
        }

        return $item;
    }

    /**
     * Replaces known container parameters key by their values
     * @param string $item
     * @return string
     */
    private function _getContainerParameters($item)
    {
        $matches = array();
        if (preg_match('/^%([^%]+)%$/', $item, $matches)) {
            if ($this->hasParameter($matches[1])) {
                return $this->getParameter($matches[1]);
            }
        }

        if (preg_match_all('/%([^%]+)%/', $item, $matches, PREG_PATTERN_ORDER)) {
            foreach ($matches[1] as $expr) {
                if ($this->hasParameter($expr)) {
                    $item = str_replace('%' . $expr . '%', $this->getParameter($expr), $item);
                }
            }
        }

        return $item;
    }

    /**
     * Returns the associated service to item if exists, item itself otherwise
     * @param string $item
     * @return mixed
     */
    private function _getContainerServices($item)
    {
        if (false === is_string($item)) {
            return $item;
        }

        $matches = array();
        if (preg_match('/^@([a-z0-9.-]+)$/i', trim($item), $matches)) {
            if ($this->has($matches[1])) {
                return $this->get($matches[1]);
            }
        }

        return $item;
    }

    /**
     * Returns true if the given service is loaded.
     *
     * @param string $id The service identifier
     *
     * @return Boolean true if the service is loaded, false otherwise
     */
    public function isLoaded($id)
    {
        $id = strtolower($id);

        return isset($this->services[$id]) || method_exists($this, 'get' . strtr($id, array('_' => '', '.' => '_')) . 'Service');
    }
}