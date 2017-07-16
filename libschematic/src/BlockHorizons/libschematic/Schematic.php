<?php
namespace BlockHorizons\libschematic;

use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Server;

class Schematic {

	/**
	 * For schematics exported from Minecraft Alpha and newer
	 */
	const MATERIALS_ALPHA = "Alpha";

	/**
	 * For schematics exported from Minecraft Classic
	 */
	const MATERIALS_CLASSIC = "Classic";

	/**
	 * Fallback
	 */
	const MATERIALS_UNKNOWN = "Unknown";

	/** @var string */
	public $raw;

	/**
	 * Order YXZ:
	 * Height    - Along Y axis
	 * Width     - Along X axis
	 * Length    - Along Z axis
	 * @var int
	 */
	protected $height, $width, $length;

	/** @var Block[] */
	protected $blocks = [];

	/** @var string */
	protected $materials = self::MATERIALS_UNKNOWN;

	/** @var CompoundTag */
	protected $entities;

	/** @var CompoundTag */
	protected $tileEntities;

	/**
	 * @param string $data Schematic file contents
	 */
	public function __construct(string $data = "") {
		$this->raw = $data;
	}

	/**
	 * Raw -> NBT -> Class properties
	 */
	public function decode() {
		if(empty($this->raw)) {
			throw new \InvalidStateException("no data to decode");
		}
		try {
			$nbt = $this->getNBT();
			$data = $nbt->getData();

			$this->width = $data["Width"];
			$this->height = $data["Height"];
			$this->length = $data["Length"];
			$this->materials = $data["Materials"];

			$this->blocks = self::decodeBlocks($data["Blocks"], $data["Data"], $this->height, $this->width, $this->length);

			$this->entities = $data["Entities"];
			$this->tileEntities = $data["TileEntities"];

		} catch(\Throwable $e) { //zlib decode error / corrupt data
			Server::getInstance()->getLogger()->error("Error decoding schematic: " . $e->getMessage());
		}
	}

	public function getNBT() {
		$nbt = new NBT(NBT::BIG_ENDIAN);
		$nbt->readCompressed($this->raw);
		return $nbt;
	}

	public static function decodeBlocks(string $blocks, string $meta, int $height, int $width, int $length): array {
		$bytes = array_values(unpack("c*", $blocks));
		$meta = array_values(unpack("c*", $meta));
		$realBlocks = [];
		for($x = 0; $x < $width; $x++) {
			for($y = 0; $y < $height; $y++) {
				for($z = 0; $z < $length; $z++) {
					$index = ($y * $length + $z) * $width + $x;
					$block = Block::get($bytes[$index]);
					$block->setComponents($x, $y, $z);
					if(isset($meta[$index])) {
						$block->setDamage($meta[$index] & 0x0F);
					}
					$realBlocks[] = $block;
				}
			}
		}
		return $realBlocks;
	}

	/**
	 * Class properties into NBT -> Raw
	 */
	public function encode() {
		// Get real parameters from last block in the array
		$lb = array_reverse($this->blocks)[0] ?? null;
		$this->height = $lb ? $lb->y + 1 : 0;
		$this->width = $lb ? $lb->x + 1 : 0;
		$this->length = $lb ? $lb->z + 1 : 0;
		$eb = self::encodeBlocks($this->blocks, $this->height, $this->width, $this->length);

		$nbt = new NBT(NBT::BIG_ENDIAN);
		$nbtCompound = new CompoundTag("Schematic", [
			new ByteArrayTag("Blocks", $eb[0]),
			new ByteArrayTag("Data", $eb[1]),
			new ShortTag("Length", $this->length),
			new ShortTag("Width", $this->width),
			new ShortTag("Height", $this->height),
			new StringTag("Materials", self::MATERIALS_ALPHA)
		]);
		$nbt->setData($nbtCompound);
		$this->raw = $nbt->writeCompressed();
	}

	/**
	 * @param Block[] $blocks
	 * @param int     $height
	 * @param int     $width
	 * @param int     $length
	 *
	 * @return array
	 */
	public static function encodeBlocks(array $blocks, int $height, int $width, int $length): array {
		$meta = "";
		$data = "";
		for($x = 0; $x < $width; $x++) {
			for($y = 0; $y < $height; $y++) {
				for($z = 0; $z < $length; $z++) {
					$index = ($y * $length + $z) * $width + $x;
					$block = $blocks[$index];
					$data .= pack("c", $block->getId());
					$meta .= pack("c", $block->getDamage() & 0x0F); // TODO: Check if this is right method to get lowest 4 bits
				}
			}
		}
		return [$data, $meta];
	}
	
	/**
	 * Replaces blocks that are not currently available in PocketMine-MP.
	 */
	public function fixBlockIds() {
		foreach($this->blocks as $k => $block) {
			$replace = null;
			switch($block->getId()) {
				case 126:
					$replace = Block::get(Block::WOOD_SLAB, $block->getDamage());
					break;
				case 95:
					$replace = Block::get(Block::GLASS);
					break;
				case 160:
					$replace = Block::get(Block::GLASS_PANE);
					break;
				case 125:
					$replace = Block::get(Block::DOUBLE_WOODEN_SLAB, $block->getDamage());
					break;
				case 188:
					$replace = Block::get(Block::FENCE, 1);
					break;
				case 189:
					$replace = Block::get(Block::FENCE, 2);
					break;
				case 190:
					$replace = Block::get(Block::FENCE, 3);
					break;
				case 191:
					$replace = Block::get(Block::FENCE, 5);
					break;
				case 192:
					$replace = Block::get(Block::FENCE, 4);
					break;
				default:
					break;
			}
			if($replace) {
				$replace->setComponents($block->x, $block->y, $block->z);
				$this->blocks[$k] = $replace;
			}
		}
	}
	
	/**
	 * Returns all blocks in the schematic.
	 *
	 * @return array
	 */
	public function getBlocks(): array {
		return $this->blocks;
	}

	/**
	 * Blocks must follow YXZ order or you will corrupt schematic file!
	 *
	 * @param Block[] $blocks
	 */
	public function setBlocks(array $blocks) {
		$this->blocks = $blocks;
	}

	public function getMaterials(): string {
		return $this->materials;
	}

	public function setMaterials(string $materials) {
		$this->materials = $materials;
	}
	
	/**
	 * Returns all entities in the schematic.
	 * 
	 * @return CompoundTag
	 */
	public function getEntities() {
		return $this->entities;
	}

	/**
	 * @param CompoundTag
	 */
	public function setEntities($entities) {
		$this->entities;
	}

	public function getTileEntities() {
		return $this->tileEntities;
	}

	/**
	 * @param CompoundTag
	 */
	public function setTileEntities($entities) {
		$this->tileEntities = $entities;
	}

	public function getLength(): int {
		return $this->length;
	}

	public function getHeight(): int {
		return $this->height;
	}

	public function getWidth(): int {
		return $this->width;
	}
}