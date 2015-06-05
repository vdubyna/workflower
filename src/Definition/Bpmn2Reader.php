<?php
/*
 * Copyright (c) 2015 KUBO Atsuhiro <kubo@iteman.jp>,
 * All rights reserved.
 *
 * This file is part of Workflower.
 *
 * This program and the accompanying materials are made available under
 * the terms of the BSD 2-Clause License which accompanies this
 * distribution, and is available at http://opensource.org/licenses/BSD-2-Clause
 */

namespace PHPMentors\Workflower\Definition;

use PHPMentors\DomainKata\Service\ServiceInterface;
use PHPMentors\Workflower\Workflow\Workflow;
use PHPMentors\Workflower\Workflow\WorkflowBuilder;

class Bpmn2Reader implements ServiceInterface
{
    /**
     * @var string
     */
    private $schema;

    /**
     * @param string $schema
     */
    public function __construct($schema)
    {
        $this->schema = $schema;
    }

    /**
     * @param string $file
     *
     * @return Workflow
     *
     * @throws Bpmn2ReaderException
     */
    public function read($file)
    {
        $document = new \DOMDocument();
        $schema = $this->schema;
        $errorToExceptionContext = new ErrorToExceptionContext(E_WARNING, function () use ($file, $document, $schema) {
            try {
                $result = $document->load($file);
                if (!$result) {
                    throw new \LogicException('foo');
                }

                $result = $document->schemaValidate($schema);
                if (!$result) {
                    throw new \LogicException('bar');
                }
            } catch (\Exception $e) {
                throw $e;
            }
        });
        $errorToExceptionContext->invoke();

        $workflowBuilder = new WorkflowBuilder();

        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'process') as $element) {
            if ($element->hasAttribute('id')) {
                $workflowId = $element->getAttribute('id');
            }

            if ($element->hasAttribute('name')) {
                $workflowBuilder->setWorkflowName($element->getAttribute('name'));
            }
        }
        if (!isset($workflowId)) {
            $workflowId = pathinfo($file, PATHINFO_FILENAME);
        }
        $workflowBuilder->setWorkflowId($workflowId);

        $flowObjectRoles = array();
        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'lane') as $element) {
            if (!$element->hasAttribute('id')) {
                throw $this->createBpmn2ReaderException($element, $file);
            }

            $workflowBuilder->addRole(
                $element->getAttribute('id'),
                $element->hasAttribute('name') ? $element->getAttribute('name') : null
            );

            foreach ($element->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'flowNodeRef') as $childElement) {
                $flowObjectRoles[$childElement->nodeValue] = $element->getAttribute('id');
            }
        }

        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'startEvent') as $element) {
            if (!$element->hasAttribute('id')) {
                throw $this->createBpmn2ReaderException($element, $file);
            }

            $workflowBuilder->addStartEvent(
                $element->getAttribute('id'),
                $flowObjectRoles[$element->getAttribute('id')],
                $element->hasAttribute('name') ? $element->getAttribute('name') : null,
                $element->hasAttribute('default') ? $element->getAttribute('default') : null
            );
        }

        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'task') as $element) {
            if (!$element->hasAttribute('id')) {
                throw $this->createBpmn2ReaderException($element, $file);
            }

            $workflowBuilder->addTask(
                $element->getAttribute('id'),
                $flowObjectRoles[$element->getAttribute('id')],
                $element->hasAttribute('name') ? $element->getAttribute('name') : null,
                $element->hasAttribute('default') ? $element->getAttribute('default') : null
            );
        }

        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'exclusiveGateway') as $element) {
            if (!$element->hasAttribute('id')) {
                throw $this->createBpmn2ReaderException($element, $file);
            }

            $workflowBuilder->addExclusiveGateway(
                $element->getAttribute('id'),
                $flowObjectRoles[$element->getAttribute('id')],
                $element->hasAttribute('name') ? $element->getAttribute('name') : null,
                $element->hasAttribute('default') ? $element->getAttribute('default') : null
            );
        }

        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'endEvent') as $element) {
            if (!$element->hasAttribute('id')) {
                throw $this->createBpmn2ReaderException($element, $file);
            }

            $workflowBuilder->addEndEvent($element->getAttribute('id'), $flowObjectRoles[$element->getAttribute('id')], $element->hasAttribute('name') ? $element->getAttribute('name') : null);
        }

        foreach ($document->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'sequenceFlow') as $element) {
            if (!$element->hasAttribute('id')) {
                throw $this->createBpmn2ReaderException($element, $file);
            }

            $condition = null;
            foreach ($element->getElementsByTagNameNs('http://www.omg.org/spec/BPMN/20100524/MODEL', 'conditionExpression') as $childElement) {
                $condition = $childElement->nodeValue;
                break;
            }

            $workflowBuilder->addSequenceFlow(
                $element->getAttribute('sourceRef'),
                $element->getAttribute('targetRef'),
                $element->getAttribute('id'),
                $element->hasAttribute('name') ? $element->getAttribute('name') : null,
                $condition === null ? null : $condition
            );
        }

        return $workflowBuilder->build();
    }

    /**
     * @param \DOMElement $element
     * @param string      $file
     *
     * @return Bpmn2ReaderException
     */
    private function createBpmn2ReaderException(\DOMElement $element, $file)
    {
        return new Bpmn2ReaderException(sprintf('The id attribute of the "%s" element is not found in "%s" on line %d', $element->tagName, $file, $element->getLineNo()));
    }
}
