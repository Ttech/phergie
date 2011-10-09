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
 * @package   Phergie_Plugin_NetStats
 * @author    Nobody <ttech@mostlynothing.info>
 * @copyright 2008-2010 Phergie Development Team (http://phergie.org)
 * @license   http://phergie.org/license New BSD License
 * @link      http://pear.phergie.org/package/Phergie_Plugin_NetStats
 */

/**
 * This plugin provides ability for bots to obtain stats data from the network
 * and store it in a database for processing later. 
 *
 * There are a few configuration options, however I'm not sure if we actually
 * Need them... More to come later. 
 *
 * @category Phergie 
 * @package  Phergie_Plugin_NetStats
 * @author   Ttech <ttech@mostlynothing.info> 
 * @license  http://phergie.org/license New BSD License
 * @link     http://pear.phergie.org/package/Phergie_Plugin_NetStats
 */
class Phergie_Plugin_NetStats extends Phergie_Plugin_Abstract
{

   /**
   *  SQLite Object
   *
   *  @var resource
   */
   protected $db = null;

   /**
   * Channel Stats Array
   *
   * @var Array
   */
   protected $statChannels;

   /**
   * Channel Stats Query
   *
   * @var PDOStatement
   **/
   protected $storeChannelStatsData;

   /**
   * luser Stats Data Query
   *
   * @var PDOStatement
   **/
   protected $storeLusersData;
   /**
   * Channel Stats Query
   *
   * @var PDOStatement
   **/
   protected $getChannelStatsData;

   /**
   * LUSERS Cache Array (multiple lines)
   *
   * @var PDOStatement
   **/
   protected $lusersCacheArray = array();

   /**
   * User Stats Cache
   *  Store cache array for users to not version
   *  @var Array
   */
   protected $usersStatsCache;

   public function onLoad()
   {
      $plugins = $this->getPluginHandler();
      $plugins->getPlugin('Cron'); // kinda need this. 
      $plugins->getPlugin('Db');
      // Set channels we get stats for.
      $this->statChannels = $this->getconfig('NetStats.channels',false);
      // We can actually make use of this now!
      $this->db = $this->plugins->getPlugin('Db')->init(
         'NetStats/',
         'stats.db',
         'stats.sql');
         
      $this->prepareSQLStatements();
   }
   
   /**
   * Setup the sql queries we'll use thoughout the plugin
   *
   * @return void
   **/
   protected function prepareSQLStatements()
   {
      $this->storeChannelStatsData = $this->db->prepare(
         'insert into channel_stats (time,channel,users,topic)
         values(:time, :channel, :users, :topic)'
         );

      $this->storeVersionStatsData = $this->db->prepare(
         'insert into version_stats (time,user,client,version)
         values(:time, :user, :client, :version)'
         );
     $this->getClientStatsData = $this->db->prepare(
	'select (select count(distinct client) from version_stats)
 as distinct_clients,client as client,COUNT(client) as count from version_stats
 group by client order by count desc limit 1;'
	);

     $this->usersStatsCache = $this->db->prepare(
	'select time as timestamp from version_stats where
	 user=:user order by time desc limit 1;'
	);
      $this->getChannelStatsData = $this->db->prepare('
        select round(avg(users)),max(users),min(users),
(select time from channel_stats where users=(select max(users)
 from channel_stats where channel=:channel)
 and channel=:channel) from channel_stats where channel=:channel;');

      $this->storeLusersData = $this->db->prepare(
         'insert into lusers_stats(time,user_visible,user_invisible,user_total,operators,servers) values(:time, :user_visible, :user_invisible, :user_total, :operators, :servers)'
         );
   }

   public function onGetChannelUsers(){
      // We need to send a list of channels.
      foreach($this->statChannels as $channel){
         $this->doList($channel);
      }
      return;
   }

   public function onLUSERData(){
      // We need to send a list of channels.
         $this->doRaw('LUSERS');
      return;
   }

   public function StatsCallback(){
      $statArray = $this->getConfig('NetStats.enablestats',array());
      foreach($statArray as $statFunction){
         // Call each function so we can get some stats!
         call_user_func(array($this->getPluginHandler(), $statFunction));
      }
   }

   protected function processLUSERS($code,$raw_line){
      // Anything and everything lusers you would want to process here.
      switch($code){
            case Phergie_Event_Response::RPL_LUSEROP:
               $regex = '/(?<operators>\d+) :IRC Operators online/i';
            break;
            case Phergie_Event_Response::RPL_LUSERCLIENT:
               $regex = '/.*There are (?<visible_users>\d+) users and (?<invisble_users>\d+) invisible on (?<servers>\d+) servers.*/i';
            break;
      } // Stop a switch
      preg_match($regex,$raw_line,$matches);
      // Now we set the 'variables' to the main array
      // One way to fix this up??
      foreach($matches as $match => $value){
         if(!is_int($match)){
            // As long as its not an integer, we store it
            $this->lusersCacheArray[$match] = intval($value); 
         }
      }
      return;
   }

	private function processChannelStats($raw_line){
		$topic = substr($raw_line,(strpos($raw_line,':')+1));
		// Now we need to make an array of what we want. 
		// That is actually 0 = channel and 1 = users
		$line_split = explode(" ",$raw_line);
		$stat_data = array(
			':time'     => time(),
			':channel'  => $line_split[0],
			':users'    => $line_split[1],
			':topic'    => $topic
		);
		unset($raw_line,$topic,$line_split); // Garbage Collection?
		$this->storeChannelStatsData->execute($stat_data);
	}

	private function userStatsTime($user){
		$data = array(
			':user' => $user,
		);
		$this->usersStatsCache->execute($data);
		$this->usersStatsCache->bindColumn(1, $timestamp);
		$this->usersStatsCache->fetch(PDO::FETCH_BOUND);
		return intval($timestamp);
	}
	public function onJoin(){
		$user = $this->getEvent()->getNick();
		// We don't want to version our selves 
		if($user !== $nick = $this->connection->getNick()){
			if((time()-($this->userStatsTime($user))) >= 604800){
				// This needs to be fixed. Don't forget Ttech!
				$this->doVersion($user);
			} else {
				echo "DEBUG: $user is already in Stats Cache, ignoring.\n";
			}
		}
	}
  
	public function onVersion(){
		if(count($this->event->getArguments()) >= 1){
			$full_version = $this->event->getArgument(0);
		}
		$version_parts = explode(' ', $full_version);
		$user = $this->getEvent()->getNick();
		$client = $version_parts[0];
		unset($version_parts);
		$version_data = array(
			':time' => time(),
			':user' => strval($user),
			':client' => strval(trim($client)),
			':version' => trim($full_version),
		);
		$this->storeVersionStatsData->execute($version_data);
	}

	public function onCommandChannelstats($channel){
		$event = $this->getEvent();
		$source = $event->getSource();
		$nick = $event->getNick();
		$channels = $this->getconfig('NetStats.channels');
		if(in_array(strval($channel),$channels)){
			$arguments = array('channel' => strval($channel));
			$channel_stats = $this->getChannelStatsData->execute($arguments);
			// This seemed easier at the time. 
			$this->getChannelStatsData->bindColumn(1, $average_users);
			$this->getChannelStatsData->bindColumn(2, $max_users);
			$this->getChannelStatsData->bindColumn(3, $min_users);
			$this->getChannelStatsData->bindColumn(4, $max_users_date);
			$this->getChannelStatsData->fetch(PDO::FETCH_BOUND);
			$message = 'There is an average of '.substr($average_users,0,-2)
			.' users in '.$channel.
			'. The channel has also had a maximum of '.$max_users.
			' users on '.date('l jS \of F Y h:i:s A',$max_users_date).'.';
			$this->doPrivmsg($source, $message);
		} else {
			$this->doPrivmsg($source,"That is not a valid channel.");      
		}
	}

	public function onCommandClientstats(){
		$event = $this->getEvent();
		$source = $event->getSource();
		$nick = $event->getNick();
		$this->getClientStatsData->execute();
		$client_data = $this->getClientStatsData->fetch();
		$message = 'There are total of '.$client_data['distinct_clients']
		.' unique clients '
		.'in my database. With '.$client_data['client'].' being the most'
		.' popular with '.$client_data['count'].' users.';
		$this->doPrivmsg($source,$message);
	}

   public function onResponse()
   {
      // Set a few important variables 
      $code = $this->getEvent()->getCode();
      $nick = $this->connection->getNick();
      $ircraw = $this->event->getRawData();
      // Complicated, but get everything AFTER the bot's nick
		$raw_line= substr($ircraw,(strlen($nick)+1)+strpos($ircraw,$nick));
      switch ($code) {
         case Phergie_Event_Response::RPL_ENDOFMOTD:
         case Phergie_Event_Response::ERR_NOMOTD:
         // Registering a Cron Callback
         $this->plugins->getPlugin('Cron')->registerCallback(
            array($this, 'StatsCallback'),
            1800,
            array(),
            true
            );
         // Start with calling all our enabled stats
         $statArray = $this->getConfig('NetStats.enablestats',array());
         foreach($statArray as $statFunction){
            // Call each function so we can get some stats!
            call_user_func(array($this->getPluginHandler(), $statFunction));
         }
         break;
         case Phergie_Event_Response::RPL_LIST:
            // Call the processor for LIST
            $this->processChannelStats($raw_line);
         break;
         case Phergie_Event_Response::RPL_LUSERCLIENT:
         case Phergie_Event_Response::RPL_LUSEROP:
            $this->processLUSERS($code,$raw_line);
            // got number we need and store in database.
            if(count($this->lusersCacheArray) >= 4){
               $lusersCache = $this->lusersCacheArray;
               $lusers_data = array(
                        ':time' => time(),
                        ':user_visible' => $lusersCache['visible_users'],
                        ':user_invisible' => $lusersCache['invisble_users'],
                        ':user_total' => $lusersCache['visible_users'] +  $lusersCache['invisble_users'],
                        ':operators' => intval($lusersCache['operators']),
                        ':servers' => $lusersCache['servers'],
                  );
               $this->lusersCacheArray = array(); // Reset to cleanup
               $this->storeLusersData->execute($lusers_data);
            }
         break;
      }
   }
}


