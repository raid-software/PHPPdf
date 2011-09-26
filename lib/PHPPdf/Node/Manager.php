<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace PHPPdf\Node;

use PHPPdf\Util\DrawingTaskHeap;

use PHPPdf\Document;
use PHPPdf\Parser\DocumentParserListener;
use PHPPdf\Parser\Exception\DuplicatedIdException;

/**
 * Manager of nodes
 * 
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class Manager implements DocumentParserListener
{
    private $nodes = array();
    private $wrappers = array();
    
    private $managedNodes = array();
    private $behavioursTasks;
    
    public function __construct()
    {
        $this->behavioursTasks = new DrawingTaskHeap();
    }
    
    public function register($id, Node $node)
    {
        if(isset($this->nodes[$id]))
        {
            throw new DuplicatedIdException(sprintf('Duplicate of id "%s".', $id));
        }
        
        $this->nodes[$id] = $node;

        if(isset($this->wrappers[$id]))
        {
            $this->wrappers[$id]->setNode($node);
        }
    }
    
    /**
     * @return NodeAware
     */
    public function get($id)
    {
        if(isset($this->nodes[$id]))
        {
            return $this->nodes[$id];
        }
        
        if(isset($this->wrappers[$id]))
        {
            return $this->wrappers[$id];
        }
        
        $wrapper = new NodeWrapper();
        
        $this->wrappers[$id] = $wrapper;
        
        return $wrapper;
    }
    
    public function clear()
    {
        $this->behavioursTasks = new DrawingTaskHeap();
        $this->wrappers = array();
        $this->nodes = array();
    }
    
    public function onEndParsePlaceholders(Document $document, PageCollection $root, Node $node)
    {
        if($this->isPage($node))
        {
            $node->preFormat($document);
        }
    }
    
    public function onStartParseNode(Document $document, PageCollection $root, Node $node)
    {
    }
    
    private function isPage($node)
    {
        return $node instanceof \PHPPdf\Node\Page;
    }
    
    public function onEndParseNode(Document $document, PageCollection $root, Node $node)
    {
        if(!$this->isPage($node) && $this->isPage($node->getParent()))
        {
            $node->format($document);
            $node->getUnorderedDrawingTasks($document, $this->behavioursTasks);
        }
        
        $this->processDynamicPage($document, $node);
              
        if($this->isPage($node))
        {
            $node->postFormat($document);
            
            $tasks = new DrawingTaskHeap();
            if(!$this->isDynamicPage($node) || count($node->getPages()) > 0)
            {
                $node->getOrderedDrawingTasks($document, $tasks);
            }
            $node->getPostDrawingTasks($document, $tasks);
            $document->invokeTasks($tasks);
            
            $node->flush();
            $root->flush();
        }
    }
    
    private function processDynamicPage(Document $document, Node $node)
    {
        if($this->isDynamicPage($node->getParent()) && $this->isOutOfPage($node))
        {
            $dynamicPage = $node->getParent();
            $dynamicPage->postFormat($document);
            
            $pages = $dynamicPage->getAllPagesExceptsCurrent();
            
            foreach($pages as $page)
            {
                $tasks = new DrawingTaskHeap();
                $page->getOrderedDrawingTasks($document, $tasks);
                $document->invokeTasks($tasks);
                $page->flush();
            }

            $currentPage = $dynamicPage->getCurrentPage(false);
            $dynamicPage->removeAllPagesExceptsCurrent();
            
            if($currentPage)
            {
                $dynamicPage->removeAll();
                foreach($currentPage->getChildren() as $child)
                {
                    if(!$child->getAttribute('break'))
                    {
                        $dynamicPage->add($child);
                    }
                    else
                    {
                        $child->flush();
                    }
                }
                $currentPage->removeAll();
            }
        }
    }
    
    private function isDynamicPage($node)
    {
        return $node instanceof \PHPPdf\Node\DynamicPage;
    }
    
    private function isOutOfPage(Node $node)
    {
        $page = $node->getParent();
        
        if($node->getAttribute('break'))
        {
            return true;
        }
        
        return $page->getDiagonalPoint()->getY() > $node->getFirstPoint()->getY();
    }
    
    public function onEndParsing(Document $document, PageCollection $root)
    {
        $document->invokeTasks($this->behavioursTasks);
        $this->behavioursTasks = new DrawingTaskHeap();
    }
}