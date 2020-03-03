<?php

declare(strict_types=1);

namespace muqsit\formimagesfix;

use Closure;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{

	/** @var Closure[][] */
	private $callbacks = [];

	public function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	private function onPacketSend(Player $player, Closure $callback) : void{
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $currentTicK) use($player, $callback) : void{
			if($player->isOnline()){
				$ts = mt_rand() * 1000;
				$pk = new NetworkStackLatencyPacket();
				$pk->timestamp = $ts;
				$pk->needResponse = true;
				$player->sendDataPacket($pk);
				$this->callbacks[$player->getId()][$ts] = $callback;
			}
		}), 1);
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 * @priority MONITOR
	 * @ignoreCancelled true
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		if($packet instanceof NetworkStackLatencyPacket){
			$player = $event->getPlayer();
			if(isset($this->callbacks[$id = $player->getId()][$ts = $packet->timestamp])){
				$cb = $this->callbacks[$id][$ts];
				unset($this->callbacks[$id][$ts]);
				$cb();
			}
		}
	}

	/**
	 * @param DataPacketSendEvent $event
	 * @priority MONITOR
	 * @ignoreCancelled true
	 */
	public function onDataPacketSend(DataPacketSendEvent $event) : void{
		if($event->getPacket() instanceof ModalFormRequestPacket){
			$player = $event->getPlayer();
			$this->onPacketSend($event->getPlayer(), static function() use($player) : void{
				$player->addTitle("", "", 0, 0, 0);
			});
		}
	}

	/**
	 * @param PlayerQuitEvent $event
	 * @priority MONITOR
	 */
	public function onPlayerQuit(PlayerQuitEvent $event) : void{
		unset($this->callbacks[$event->getPlayer()->getId()]);
	}
}