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
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
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
	 * @ignoreCancelled true
	 */
	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		if($packet instanceof NetworkStackLatencyPacket && isset($this->callbacks[$id = $event->getOrigin()->getPlayer()->getId()][$ts = $packet->timestamp])){
			$cb = $this->callbacks[$id][$ts];
			unset($this->callbacks[$id][$ts]);
			if(count($this->callbacks[$id]) === 0){
				unset($this->callbacks[$id]);
			}
			$cb();
		}
	}

	private function requestUpdate(Player $player) : void{
		$attribute = $player->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL);
		$pk = new UpdateAttributesPacket();
		$pk->entityRuntimeId = $player->getId();
		$pk->entries[] = new NetworkAttribute($attribute->getId(), $attribute->getMinValue(), $attribute->getMaxValue(), $attribute->getValue(), $attribute->getDefaultValue());
		$player->getNetworkSession()->sendDataPacket($pk);
	}

	/**
	 * @param DataPacketSendEvent $event
	 * @priority MONITOR
	 * @ignoreCancelled true
	 */
	public function onDataPacketSend(DataPacketSendEvent $event) : void{
		foreach($event->getPackets() as $packet){
			if($packet instanceof ModalFormRequestPacket){
				foreach($event->getTargets() as $session){
					$player = $session->getPlayer();
					$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $currentTick) use($player) : void{
						if($player->isOnline()){
							$this->onPacketSend($player, function() use($player) : void{
								if($player->isOnline()){
									$this->requestUpdate($player);
									if($this->times_to_request > 1){
										$times = $this->times_to_request - 1;
										/** @var TaskHandler|null $handler */
										$handler = null;
										$handler = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $currentTick) use($player, $times, &$handler) : void{
											if(--$times >= 0 && $player->isOnline()){
												$this->requestUpdate($player);
											}else{
												$handler->cancel();
												$handler = null;
											}
										}), 10);
									}
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
