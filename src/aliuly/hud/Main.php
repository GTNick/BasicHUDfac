<?php
/**
 ** CONFIG:main
 **/
namespace aliuly\hud;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\scheduler\PluginTask;
use pocketmine\Server;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\permission\Permission;
use aliuly\hud\common\mc;
use aliuly\hud\common\MPMU;

interface Formatter {
	static public function formatString(Main $plugin,$format,Player $player);
}
abstract class FixedFormat implements Formatter {
	static public function formatString(Main $plugin,$format,Player $player) {
		return $format;
	}
}
abstract class PhpFormat implements Formatter {
	static public function formatString(Main $plugin,$format,Player $player) {
		ob_start();
		eval("?>".$format);
		return ob_get_clean();
	}
}
abstract class StrtrFormat implements Formatter {
	static public function formatString(Main $plugin,$format,Player $player) {
		$vars = $plugin->getVars($player);
		return strtr($format,$vars);
	}
}

class PopupTask extends PluginTask{
	public function __construct(Main $plugin){
		parent::__construct($plugin);
	}

	public function getPlugin(){
		return $this->owner;
	}

	public function onRun($currentTick){
		$plugin = $this->getPlugin();
		if ($plugin->isDisabled()) return;

		foreach ($plugin->getServer()->getOnlinePlayers() as $pl) {
			if (!$pl->hasPermission("basichud.user")) continue;
			$msg = $plugin->getMessage($pl);
			if ($msg !== null) $pl->sendPopup($msg);
		}
	}

}

class Main extends PluginBase implements Listener,CommandExecutor {
	protected $_getMessage;		// Message function (to disabled)
	protected $_getVars;			// Customize variables

	protected $format;				// HUD format
	protected $sendPopup;			// Message to popup through API
	protected $disabled;			// HUD disabled by command
	protected $perms;					// Attachable permissions
	protected $consts;				// These are constant variables...
	protected $perms_cache;		// Permissions cache

	static public function pickFormatter($format) {
		if (strpos($format,"<?php") !== false|| strpos($format,"<?=") !== false) {
			return __NAMESPACE__."\\PhpFormat";
		}
		if (strpos($format,"{") !== false && strpos($format,"}")) {
			return __NAMESPACE__."\\StrtrFormat";
		}
		return __NAMESPACE__."\\FixedFormat";
	}

	static public function bearing($deg) {
		// Determine bearing
		if (22.5 <= $deg && $deg < 67.5) {
			return "Northwest";
		} elseif (67.5 <= $deg && $deg < 112.5) {
			return "North";
		} elseif (112.5 <= $deg && $deg < 157.5) {
			return "Northeast";
		} elseif (157.5 <= $deg && $deg < 202.5) {
			return "East";
		} elseif (202.5 <= $deg && $deg < 247.5) {
			return "Southeast";
		} elseif (247.5 <= $deg && $deg < 292.5) {
			return "South";
		} elseif (292.5 <= $deg && $deg < 337.5) {
			return "Southwest";
		} else {
			return "West";
		}
		return (int)$deg;
	}

	/**
	 * Gets the contents of an embedded resource on the plugin file.
	 *
	 * @param string $filename
	 *
	 * @return string, or null
	 */
	public function getResourceContents($filename){
		$fp = $this->getResource($filename);
		if($fp === null){
			return null;
		}
		$contents = stream_get_contents($fp);
		fclose($fp);
		return $contents;
	}

	private function changePermission($player,$perm,$bool) {
		$n = strtolower($player->getName());
		if (!isset($this->perms[$n])) {
			$this->perms[$n] = $player->addAttachment($this);
		}
		$attach = $this->perms[$n];
		$attach->setPermission($perm,$bool);
		if (isset($this->perms_cache[$n])) unset($this->perms_cache[$n]);
	}

	public function getMessage($player) {
		$fn = $this->_getMessage;
		return $fn($this,$player);
	}

	public function getVars($player) {
		$this->economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
		$vars = $this->consts;
		foreach ([
		                "{money}" => $this->economy->myMoney($player),
		                "{ONLINE}" => count($this->getServer()->getOnlinePlayers());
		                "{MAXONLINE}" => $this->getServer()->getMaxPlayers();
				"{bearing}" => self::bearing($player->getYaw()),
			] as $a => $b) {
			$vars[$a] = $b;
		}
  	$fn = $this->_getVars;
		$fn($this,$vars,$player);
		return $vars;
	}
	public function getVars($player) {
		$fP = $this->getServer()->getPluginManager()->getPlugin("FactionsPro");
                                  $faction = $fP->getPlayerFaction($player->getName());
		foreach ([
		                "{faction}" => $this->getPlayerFaction($player->getName),
		                "{power}" => $this->getFactionPower($player->getFaction->getFactionPower));
		                "{MAXONLINE}" => $this->getServer()->getMaxPlayers();
				"{bearing}" => self::bearing($player->getYaw()),
			] as $a => $b) {
			$vars[$a] = $b;
		}
  	$fn = $this->_getVars;
		$fn($this,$vars,$player);
		return $vars;
	}
	public function defaultGetMessage($player) {
		$n = strtolower($player->getName());
		if (isset($this->sendPopup[$n])) {
			// An API user wants to post a Popup...
			list($msg,$timer) = $this->sendPopup[$n];
			if (microtime(true) < $timer) return $msg;
			unset($this->sendPopup[$n]);
		}
		if (isset($this->disabled[$n])) return null;

		// Manage custom groups
		if (is_array($this->format[0])) {
			if (!isset($this->perms_cache[$n])) {
				$i = 0;
				foreach ($this->format as $rr) {
					list($rank,$fmt,$formatter) = $rr;
					if ($player->hasPermission("basichud.rank.".$rank)) {
						$this->perms_cache[$n] = $i;
						break;
					}
					++$i;
				}
			} else {
				list($rank,$fmt,$formatter) = $this->format[$rank = $this->perms_cache[$n]];
			}
		} else {
			list($fmt,$formatter) = $this->format;
		}
		$txt = $formatter::formatString($this,$fmt,$player);
		return $txt;
	}

	public function onEnable(){
		$this->disabled = [];
		$this->sendPopup = [];
		$this->perms = [];

		if (!is_dir($this->getDataFolder())) mkdir($this->getDataFolder());
		/* Save default resources */
		$this->saveResource("message-example.php",true);
		$this->saveResource("vars-example.php",true);
		mc::plugin_init($this,$this->getFile());

		// These are constants that should be pre calculated
		$this->consts = [
			"{10SPACE}" => str_repeat(" ",10),
			"{20SPACE}" => str_repeat(" ",20),
			"{30SPACE}" => str_repeat(" ",30),
			"{40SPACE}" => str_repeat(" ",40),
			"{50SPACE}" => str_repeat(" ",50),
			"{NL}" => "\n",
		];


		$defaults = [
			"version" => $this->getDescription()->getVersion(),
			"# ticks" => "How often to refresh the popup",
			"ticks" => 10,
			"# format" => "Display format",
			"format" => "{GREEN}{BasicHUD} {WHITE}{world} ({x},{y},{z}) {bearing} {money}",
		];

		$cf = (new Config($this->getDataFolder()."config.yml",
								Config::YAML,$defaults))->getAll();

		if (is_array($cf["format"])) {
			// Multiple format specified...
			// save them and also register the appropriate permissions
			$this->format = [];
			foreach ($cf["format"] as $rank=>$fmt) {
				$this->format[] = [ $rank, $fmt, self::pickFormatter($fmt) ];
				$p = new Permission("basichud.rank.".$rank,
										  "BasicHUD format ".$rank, false);
				$this->getServer()->getPluginManager()->addPermission($p);
			}
		} else {
			// Single format only
			$this->format = [ $cf["format"], self::pickFormatter($cf["format"]) ];
		}
		$code = '$this->_getMessage = function($plugin,$player){';
		if (file_exists($this->getDataFolder()."message.php")) {
			$code .= file_get_contents($this->getDataFolder()."message.php");
		} else {
			$code .= $this->getResourceContents("message-example.php");
		}
		$code .= '};';
		eval($code);

		if (file_exists($this->getDataFolder()."vars.php")) {
			$code = '$this->_getVars = function($plugin,&$vars,$player){' ."\n".
					file_get_contents($this->getDataFolder()."vars.php").
					'};'."\n";
			eval($code);
		} else {
			// Empty function (this means we do not need to test _getVars)
			$this->_getVars = function(){};
		}
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new PopupTask($this), $cf["ticks"]);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	// We clear the permissions cache in the event of a command
	// next time we schedule to fetch the HUD message it will be recomputed

	public function onQuit(PlayerQuitEvent $ev) {
		$n = strtolower($ev->getPlayer()->getName());
		if (isset($this->perms_cache[$n])) unset($this->perms_cache[$n]);
		if (isset($this->sendPopup[$n])) unset($this->sendPopup[$n]);
		if (isset($this->disabled[$n])) unset($this->disabled[$n]);
		if (isset($this->perms[$n])) {
			$attach = $this->perms[$n];
			unset($this->perms[$n]);
			$ev->getPlayer()->removeAttachment($attach);
		}
	}
	public function onItemHeld(PlayerItemHeldEvent $ev){
		$this->sendPopup($ev->getPlayer(),MPMU::itemName($ev->getItem()),2);
	}

	/**
	 * @API
	 */
	public function sendPopup($player,$msg,$length=3) {
		if ($this->isEnabled()) {
			if ($player->hasPermission("basichud.user")) {
				$n = strtolower($player->getName());
				$this->sendPopup[$n] = [ $msg, microtime(true)+$length ];
				$msg = $this->getMessage($player);
			}
		}
		if ($msg !== null) $player->sendPopup($msg);
	}
}
