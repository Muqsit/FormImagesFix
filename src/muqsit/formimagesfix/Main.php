<?php

declare(strict_types=1);

namespace muqsit\formimagesfix;

use Closure;
use pocketmine\entity\Attribute;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CancelTaskException;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{

	/** @var Closure[][] */
	private $callbacks = [];

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	private function onPacketSend(Player $player, Closure $callback) : void{
		$ts = mt_rand() * 1000;
		$pk = new NetworkStackLatencyPacket();
		$pk->timestamp = $ts;
		$pk->needResponse = true;
		$player->getNetworkSession()->sendDataPacket($pk);
		$this->callbacks[$player->getId()][$ts] = $callback;
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 * @priority MONITOR
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		if($packet instanceof NetworkStackLatencyPacket){
			$player = $event->getOrigin()->getPlayer();
			if($player !== null && isset($this->callbacks[$id = $player->getId()][$ts = $packet->timestamp])){
				$cb = $this->callbacks[$id][$ts];
				unset($this->callbacks[$id][$ts]);
				if(count($this->callbacks[$id]) === 0){
					unset($this->callbacks[$id]);
				}
				$cb();
			}
		}
	}

	/**
	 * @param DataPacketSendEvent $event
	 * @priority MONITOR
	 */
	public function onDataPacketSend(DataPacketSendEvent $event) : void{
		foreach($event->getPackets() as $packet){
			if($packet instanceof ModalFormRequestPacket){
				foreach($event->getTargets() as $target){
					$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($target) : void{
						$player = $target->getPlayer();
						if($player !== null && $player->isOnline()){
							$this->onPacketSend($player, function() use($player, $target) : void{
								if($player->isOnline()){
									$times = 5; // send for up to 5 x 10 ticks (or 2500ms)
									$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(static function() use($player, $target, &$times) : void{
										if(--$times >= 0 && $target->isConnected()){
											$entries = [];
											$attr = $player->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL);
											/** @noinspection NullPointerExceptionInspection */
											$entries[] = new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue());
											$target->sendDataPacket(UpdateAttributesPacket::create($player->getId(), $entries, 0));
											return;
										}

										throw new CancelTaskException("Maximum retries exceeded");
									}), 10);
								}
							});
						}
					}), 1);
				}
			}
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
