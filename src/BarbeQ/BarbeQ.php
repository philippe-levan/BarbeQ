<?php

/**
 * This file is part of BarbeQ
 *
 * (c) anonymation <contact@anonymation.com>
 *
 */
namespace BarbeQ;

use BarbeQ\Adapter\AdapterInterface;
use BarbeQ\Consumer\ConsumerInterface;
use BarbeQ\Event\ConsumeEvent;
use BarbeQ\Exception\ConsumerIndigestionException;
use BarbeQ\Iterator\MessageIteratorInterface;
use BarbeQ\Model\MessageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class BarbeQ
{
    protected $adapter;
    protected $messageDispatcher;
    protected $dispatcher;
    protected $consumingTag;

    public function __construct(
        AdapterInterface $adapter,
        EventDispatcherInterface $messageDispatcher,
        EventDispatcherInterface $dispatcher
    )
    {
        $this->adapter = $adapter;
        $this->messageDispatcher = $messageDispatcher;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @return AdapterInterface
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Publish a message
     *
     * @param string           $queue Target queue to push message in
     * @param MessageInterface $message Message content
     */
    public function publish($queue, MessageInterface $message)
    {
        $message->setQueue($queue);
        $this->getAdapter()->publish($queue, $message);
    }

    /**
     * Publish a message
     * Proxy method for publish()
     *
     * @param string           $queue Target queue to push message in
     * @param MessageInterface $message Message content
     */
    public function cook($queue, MessageInterface $message)
    {
        $this->publish($queue, $message);
    }

    /**
     * @return MessageIteratorInterface
     */
    public function getMessages($queue = null)
    {
        return $this->adapter->getMessages($queue);
    }

    /**
     * Consumes n messages and calls $callback on each iteration
     *
     * @param  string   $queue
     * @param  int      $amount
     * @param  \Closure $callback
     *
     * @return void
     */
    public function consume($queue, $amount = 50, \Closure $callback)
    {
        $i = 0;
        foreach ($this->getMessages($queue) as $message) {
            $i++;
            $this->consumeOne($message);
            $callback($i, $message);

            if ($i >= $amount) {
                $this->stopConsuming();

                return;
            }
        }
    }

    /**
     * Dispatches a Message to all interested consumers
     *
     * @param  MessageInterface $message
     *
     * @throws ConsumerIndigestionException
     */
    public function consumeOne(MessageInterface $message)
    {
        $consumeEvent = new ConsumeEvent($message);

        try {
            $this->dispatcher->dispatch(BarbeQEvents::PRE_CONSUME, $consumeEvent);

            $message->start();

            $this->messageDispatcher->dispatch($message->getQueue(), $consumeEvent);
            $this->adapter->onSuccess($message);

            $message->complete();
        } catch(ConsumerIndigestionException $e) {
            $this->adapter->onError($message);

            $message->completeWithError();

            $this->dispatcher->dispatch(BarbeQEvents::POST_CONSUME, $consumeEvent);

            // TODO
            throw new ConsumerIndigestionException("Error while consuming a message", 0, $e);
        }
    }

    /**
     * Consumes n messages and calls $callback on each iteration.
     * Proxy method for consume()
     *
     * @param  string   $queue
     * @param  int      $amount
     * @param  \Closure $callback
     *
     * @return void
     */
    public function eat($queue, $amount = 50, \Closure $callback)
    {
        $this->consume($queue, $amount, $callback);
    }

    /**
     * Adds a consumer for messages from the given queue name
     *
     * @param string            $queue
     * @param ConsumerInterface $consumer
     * @param int               $priority
     *
     * @return void
     */
    public function addConsumer($queue, ConsumerInterface $consumer, $priority = 0)
    {
        $this->messageDispatcher->addListener($queue, array($consumer, 'consume'), $priority);
    }

    /**
     * Stops consuming
     */
    public function stopConsuming()
    {
        $this->getAdapter()->stopConsuming();
    }
}