<?php

namespace Ladb\CoreBundle\Entity\Knowledge\School;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Ladb\CoreBundle\Validator\Constraints as LadbAssert;
use Ladb\CoreBundle\Model\AuthoredTrait;
use Ladb\CoreBundle\Model\BodiedTrait;
use Ladb\CoreBundle\Model\BodiedInterface;

/**
 * @ORM\Table("tbl_knowledge2_school_education")
 * @ORM\Entity(repositoryClass="Ladb\CoreBundle\Repository\Knowledge\School\EducationRepository")
 */
class Education implements BodiedInterface {

	use AuthoredTrait, BodiedTrait;

	const CLASS_NAME = 'LadbCoreBundle:Knowledge\School\Education';
	/**
	 * @ORM\Column(name="created_at", type="datetime")
	 * @Gedmo\Timestampable(on="create")
	 */
	protected $createdAt;
	/**
	 * @ORM\Column(name="id", type="integer")
	 * @ORM\Id
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	private $id;
	/**
	 * @ORM\ManyToOne(targetEntity="Ladb\CoreBundle\Entity\Knowledge\School", inversedBy="educations")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $school;

	/**
	 * @ORM\ManyToOne(targetEntity="Ladb\CoreBundle\Entity\Core\User")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $user;

	/**
	 * @ORM\Column(type="text", nullable=true)
	 * @Assert\Length(max=5000)
	 * @LadbAssert\NoMediaLink()
	 */
	private $body;

	/**
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $htmlBody;

	/**
	 * @ORM\Column(type="string", nullable=true)
	 */
	private $diploma;

	/**
	 * @ORM\Column(type="integer")
	 */
	private $fromYear;

	/**
	 * @ORM\Column(type="integer")
	 */
	private $toYear;

	/////

	// Id /////

	public function getId() {
		return $this->id;
	}

	// CreatedAt /////

	public function getCreatedAt() {
		return $this->createdAt;
	}

	public function setCreatedAt($createdAt) {
		$this->createdAt = $createdAt;
		return $this;
	}

	// School /////

	public function getSchool() {
		return $this->school;
	}

	public function setSchool(\Ladb\CoreBundle\Entity\Knowledge\School $school = null) {
		$this->school = $school;
		return $this;
	}

	// Diploma /////

	public function getDiploma() {
		return $this->diploma;
	}

	public function setDiploma($diploma) {
		$this->diploma = $diploma;
		return $this;
	}

	// FromYear /////

	public function getFromYear() {
		return $this->fromYear;
	}

	public function setFromYear($fromYear) {
		$this->fromYear = $fromYear;
		return $this;
	}

	// ToYear /////

	public function getToYear() {
		return $this->toYear;
	}

	public function setToYear($toYear) {
		$this->toYear = $toYear;
		return $this;
	}

}