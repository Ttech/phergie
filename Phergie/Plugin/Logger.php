<?php
/**
 * Phergie
 *
 * PHP version 5
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://phergie.org/license
 *
 * @category  Phergie
 * @package   Phergie_Plugin_Logger
 * @author    Phergie Development Team <team@phergie.org>
 * @copyright 2008-2010 Phergie Development Team (http://phergie.org)
 * @license   http://phergie.org/license New BSD License
 * @link      http://pear.phergie.org/package/Phergie_Plugin_Logger
 */

/**
 * Joins a specified channel on command from a user.
 *
 * @category Phergie
 * @package  Phergie_Plugin_Logger
 * @author   Ttech <ttech@mostlynothing.info>
 * @license  http://phergie.org/license New BSD License
 * @link     http://pear.phergie.org/package/Phergie_Plugin_Logger
 * @uses     Phergie_Plugin_Command pear.phergie.org
 */
class Phergie_Plugin_Logger extends Phergie_Plugin_Abstract
{

    /**
     * End of MOTD Tick
     *
     * @var bool triggers bot
     */
    protected $endofmotd = false; // I know there is a better way

    /**
     * Log Buffer
     *
     * @var array contains information to be written to database
     */
    protected $log_buffer = array(); // stores the cache


    /**
     * Checks for dependencies.
     *
     * @return void
     */
    public function onLoad()
    {
        $plugins = $this->getPluginHandler();
        $plugins->getPlugin('Cron'); // kinda need this. Or will
        $plugins->getPlugin('Db');

        // Load the Database some database
        $this->db = $this->plugins->getPlugin('Db')->init(
            'Logger/',
            'logs.db',
            'logs.sql'
        );
        $this->prepareSQLStatements();

        if ($this->getConfig('logger.delay') > 0) {
            $this->LoggerCron();
        }
    }

    /**
     * Initialized Logger Cron
     *
     * @return void
     */
    public function LoggerCron()
    {
        $callback_function = 'LoggerWriteDelay';
        echo "DEBUG: Creating Log Write Delay Cron\n";
        $this->plugins->getPlugin('Cron')->registerCallback(
            array($this, $callback_function),
            intval($this->getConfig('logger.delay')),
            array(),
            true
        );
    }

    /**
     * Callback method for Logger Cron
     *
     * @return void
     */
    public function LoggerWriteDelay()
    {
        // Basically we need to just loop though our archive and do something
        if ($this->getConfig('logger.logtype') == 'sqlite') {
            foreach ($this->log_buffer as $log_sql) {
                if (!$this->storeLoggerLine->execute($log_sql)) {
                    echo 'DEBUG: Could not store to Database';
                }
            }
        }
    }

    /**
     * Initializes PDO Statements
     *
     * @return void
     */
    protected function prepareSQLStatements()
    {
        $this->storeLoggerLine = $this->db->prepare(
            'insert into logs (time,location,type,user,content)
            values(:time, :location, :type, :user, :content)'
        );
    }

    /**
     * Gathers information for each line fetched from IRC
     *
     * @param string $type Type of error message
     *
     * @return void
     */
    protected function addlogLine($type)
    {
        // Some useful variables that we need
        $channel = trim($this->event->getArgument(0));
        $user = strval($this->event->getNick());
        $argument_count =  count($this->event->getArguments());

        // We need to get the arguments (more then 1)
        if ($argument_count > 1) {
            $content = trim($this->event->getArgument(1));
        } else {
            $content = trim($this->event->getArgument(0));
        }
        // We need the end of the motd, so we don't store to much!
        if ($this->endofmotd) { // Please tell me the better way
            // TODO: WE need to check for stuff thebot gets that it sent
            if ($this->getEvent()->isInChannel()) {
                $this->loggerStore($type, $channel, $user, $content);
            }
        }
    }


    /**
     * Stores to selected log destination
     *
     * @param string $type
     * @param string $channel
     * @param string $user
     * @param string $content
     *
     * @return void
     */
    protected function loggerStore($type, $channel, $user, $content)
    {
        if (trim($this->getConfig('logger.logtype')) == 'sqlite') {
            $this->LoggerStoreSQL($type, $channel, $user, $content);
        } else {
            // Flat File TEXT file
        }
    }

    /**
     * Stores SQL to Database
     *
     * @param string $type, string $channel, string $user, string $content
     *
     * @return void
     */
    protected function LoggerStoreSQL($type,$channel,$user,$content)
    {
        $log_content = array(
            ':time'     => time(),
            ':location' => strval(strtolower($channel)),
            ':type'     => $type,
            ':user'     => strval($user),
            ':content'  => trim($content)
        );
        if (!is_int($this->getConfig('logger.delay'))) {
            if (!$this->storeLoggerLine->execute($log_content)) {
                echo "DEBUG: Could not store to Database\n";
            }
        } else {
            // Store each data piece
            $this->log_buffer[] = $log_content;
        }
    }

    /**
     * Intercept data sent by bot to add to log
     *
     * @return void
     */
    public function preDispatch()
    {
        $events = $this->events->getEvents();
        $user   = $this->connection->getNick();
        $type   = '';
        foreach ($events as $event) {
            switch ($event->getType()) {
            case Phergie_Event_Request::TYPE_PRIVMSG:
                $type = 'privmsg';
                break;
            case Phergie_Event_Request::TYPE_ACTION:
                $type = 'action';
            case Phergie_Event_Request::TYPE_NOTICE:
                $type = 'notice';
                break;
            }
            // Why can't we just check if $type is set?
            // if((isset($type)) AND ($this->endofmotd == TRUE)){
            switch ($event->getType()) {
            case Phergie_Event_Request::TYPE_PRIVMSG:
            case Phergie_Event_Request::TYPE_ACTION:
            case Phergie_Event_Request::TYPE_NOTICE:
                $channel = null;
                if (count($event->getArguments()) > 1) {
                    $channel = trim($event->getArgument(0));
                    $message = $event->getArgument(1);
                } else {
                    $message = $event->getArgument(0);
                }
                if ($this->endofmotd) {
                    $this->loggerStore($type, $channel, $user, $message);
                }
            }
        } // end of foreach loop
    }

    /**
     * Intercepts a message and processes any contained recognized commands.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $this->addLogLine('privmsg');
    }

    /**
     * Intercepts a notice and processes any contained recognized commands.
     *
     * @return void
     */
    public function onNotice()
    {
        $this->addLogLine('notice');
    }

    /**
     * Intercepts a join and processes any contained recognized commands.
     *
     * @return void
     */
    public function onJoin()
    {
        $this->addLogLine('join');
    }

    /**
     * Intercepts a part and processes any contained recognized commands.
     *
     * @return void
     */
    public function onPart()
    {
        $this->addLogLine('part');
    }

    /**
     * Intercepts a quit and processes any contained recognized commands.
     *
     * @return void
     */
    public function onQuit()
    {
        $this->addLogLine('quit');
    }

    /**
     * Intercepts a kick and processes any contained recognized commands.
     *
     * @return void
     */
    public function onKick()
    {
        $this->addLogLine('kick');
    }

    /**
     * Intercepts a nick message and processes any contained recognized commands.
     *
     * @return void
     */
    public function onNick()
    {
        $this->addLogLine('nick');
    }

    /**
     * Intercepts mode and processes any contained recognized commands.
     *
     * @return void
     */
    public function onMode()
    {
        $this->addLogLine('mode');
    }

    /**
     * Intercepts action and processes any contained recognized commands.
     *
     * @return void
     */
    public function onAction()
    {
        $this->addLogLine('action');
    }

    /**
     * Intercepts topic and processes any contained recognized commands.
     *
     * @return void
     */
    public function onTopic()
    {
        $this->addLogLine('topic');
    }

    /**
     * Detects end of MOTD
     *
     * @return void
     */
    public function onResponse()
    {
        switch ($this->getEvent()->getCode()) {
        case Phergie_Event_Response::RPL_ENDOFMOTD:
        case Phergie_Event_Response::ERR_NOMOTD:
            $this->endofmotd = true;
            break;
        }
    }
}
