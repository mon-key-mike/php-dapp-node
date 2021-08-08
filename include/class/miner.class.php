<?php

class Miner {


	public $address;
	public $private_key;
	public $node;
	public $miningStat;
	public $cnt = 0;
	public $block_cnt = 0;

	private $running = true;

	function __construct($address, $node)
	{
		$this->address = $address;
		$this->node = $node;
	}

	function getMiningInfo() {

		$url = $this->node."/mine.php?q=info&".XDEBUG;
		_log("Getting info from url ". $url, 3);
		$info = url_get($url);
		if(!$info) {
			_log("Error contacting peer");
			return false;
		}
		if(!$info) {
			_log("Can not retrieve mining info");
			return false;
		}
		_log("Received mining info: ".$info, 3);
		$info = json_decode($info, true);
		if ($info['status'] != "ok") {
			_log("Wrong status for node: ".json_encode($info));
			return false;
		}
		return $info;
	}

	function start() {
		global $_config;
		$block = new Block();
		$tx = new Transaction();
		$this->miningStat = [
			'started'=>time(),
			'hashes'=>0,
			'submits'=>0,
			'accepted'=>0,
			'rejected'=>0,
			'dropped'=>0,
		];
		while($this->running) {
			$this->cnt++;
//			_log("Mining cnt: ".$this->cnt);

			$info = $this->getMiningInfo();
			if($info === false) {
				_log("Can not get mining info", 0);
				return false;
			}

			if (isset($_config['sanity_sync']) && $_config['sanity_sync'] == 1) {
				_log("Sync in process - stop miner");
				return false;
			}

			$height = $info['data']['height']+1;
			$block_date = $info['data']['date'];
			$difficulty = $info['data']['difficulty'];
			$reward = $info['data']['reward'];
			$data = $info['data']['data'];
			$nodeTime = $info['data']['time'];
			$prev_block_id = $info['data']['block'];
			$blockFound = false;

			$now = time();
			$offset = $nodeTime - $now;

			$attempt = 0;

			while (!$blockFound) {
				$attempt++;
				usleep(500 * 1000);
				$now = time();
				$elapsed = $now - $offset - $block_date;
				$new_block_date = $block_date + $elapsed;
				_log("Time=now=$now nodeTime=$nodeTime offset=$offset elapsed=$elapsed",4);
				$argon = null;
				$nonce = Block::calculateNonce($this->address, $block_date, $elapsed, $argon);
				$hit = $block->calculateHit($nonce, $this->address, $height, $difficulty, $new_block_date, $elapsed);
				$target = $block->calculateTarget($difficulty, $elapsed);
				$blockFound = ($hit > 0 && $target>=0 &&  $hit > $target);
				_log("Mining attempt=$attempt height=$height difficulty=$difficulty elapsed=$elapsed hit=$hit target=$target blockFound=$blockFound", 3);
				$this->miningStat['hashes']++;
				if($attempt % 10 == 0) {
					$info = $this->getMiningInfo();
					if($info!==false) {
						_log("Checking new block from server ".$info['data']['block']. " with our block $prev_block_id", 4);
						if($info['data']['block']!= $prev_block_id) {
							_log("New block received", 3);
							$this->miningStat['dropped']++;
							break;
						}
					}
				}
			}

			if(!$blockFound) {
				continue;
			}

			$postData = http_build_query(
				[
					'argon' => $argon,
					'nonce' => $nonce,
					'height' => $height,
					'difficulty' => $difficulty,
					'address' => $this->address,
					'date'=> $new_block_date,
					'data' => json_encode($data),
					'elapsed' => $elapsed
				]
			);

			$res = url_post($this->node . "/mine.php?q=submitHash&" . XDEBUG, $postData);


			$this->miningStat['submits']++;
			$data = json_decode($res, true);
			if ($data['status'] == "ok") {
				_log("Block confirmed", 1);
				$this->miningStat['accepted']++;
			} else {
				_log("Block not confirmed: " . $res, 1);
				$this->miningStat['rejected']++;
			}

			sleep(3);

			if($this->block_cnt > 0 && $this->cnt >= $this->block_cnt) {
				break;
			}

			_log("Mining stats: ".json_encode($this->miningStat), 1);
			$minerStatFile = Miner::getStatFile();
			file_put_contents($minerStatFile, json_encode($this->miningStat));

		}

		_log("Miner stopped", 0);
	}

	static function getStatFile() {
		$file = getcwd() . "/miner_stat.json";
		return $file;
	}

}