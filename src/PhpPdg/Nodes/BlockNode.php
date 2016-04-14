<?php

namespace PhpPdg\Nodes;

use PHPCfg\Block;
use PhpPdg\Graph\NodeInterface;

class BlockNode implements NodeInterface {
	/** @var Block  */
	private $block;

	/**
	 * BlockNode constructor.
	 * @param Block $block
	 */
	public function __construct(Block $block) {
		$this->block = $block;
	}

	/**
	 * @return Block
	 */
	public function getBlock() {
		return $this->block;
	}

	public function toString() {
		return 'Block (' . $this->getHash() . ')';
	}

	public function getHash() {
		return spl_object_hash($this->block);
	}
}