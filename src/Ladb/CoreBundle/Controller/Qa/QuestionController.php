<?php

namespace Ladb\CoreBundle\Controller\Qa;

use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Controller\PublicationControllerTrait;
use Ladb\CoreBundle\Entity\Core\Tip;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Utils\MaybeUtils;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Utils\CollectionnableUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Form\Type\Qa\QuestionType;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FollowerUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\ExplorableUtils;
use Ladb\CoreBundle\Utils\VotableUtils;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\PublicationsEvent;
use Ladb\CoreBundle\Manager\Qa\QuestionManager;
use Ladb\CoreBundle\Manager\Core\WitnessManager;
use Ladb\CoreBundle\Model\HiddableInterface;

/**
 * @Route("/questions")
 */
class QuestionController extends AbstractController {

	use PublicationControllerTrait;

	/**
	 * @Route("/new", name="core_qa_question_new")
	 * @Template("LadbCoreBundle:Qa/Question:new.html.twig")
	 */
	public function newAction(Request $request) {

		$question = new Question();
		$question->addBodyBlock(new \Ladb\CoreBundle\Entity\Core\Block\Text());	// Add a default Text body block
		$form = $this->createForm(QuestionType::class, $question);

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'form'         => $form->createView(),
			'owner'        => $this->retrieveOwner($request),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	/**
	 * @Route("/create", methods={"POST"}, name="core_qa_question_create")
	 * @Template("LadbCoreBundle:Qa/Question:new.html.twig")
	 */
	public function createAction(Request $request) {

		$owner = $this->retrieveOwner($request);

		$this->createLock('core_qa_question_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		$om = $this->getDoctrine()->getManager();

		$question = new Question();
		$form = $this->createForm(QuestionType::class, $question);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$blockBodiedUtils = $this->get(BlockBodiedUtils::NAME);
			$blockBodiedUtils->preprocessBlocks($question);

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($question);

			if ($question->getBodyBlockPictureCount() > 0) {
				$question->setMainPicture($blockBodiedUtils->getFirstPicture($question));
			}

			$question->setUser($owner);
			$owner->getMeta()->incrementPrivateQuestionCount();

			$om->persist($question);
			$om->flush();

			// Dispatch publication event
			$dispatcher = $this->get('event_dispatcher');
			$dispatcher->dispatch(PublicationListener::PUBLICATION_CREATED, new PublicationEvent($question));

			return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )));
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		$tagUtils = $this->get(TagUtils::NAME);



		return array(
			'question'         => $question,
			'form'         => $form->createView(),
			'owner'        => $owner,
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	/**
	 * @Route("/{id}/lock", requirements={"id" = "\d+"}, defaults={"lock" = true}, name="core_qa_question_lock")
	 * @Route("/{id}/unlock", requirements={"id" = "\d+"}, defaults={"lock" = false}, name="core_qa_question_unlock")
	 */
	public function lockUnlockAction($id, $lock) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertLockUnlockable($question, $lock);

		// Lock or Unlock
		$questionManager = $this->get(QuestionManager::NAME);
		if ($lock) {
			$questionManager->lock($question);
		} else {
			$questionManager->unlock($question);
		}

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('qa.question.form.alert.'.($lock ? 'lock' : 'unlock').'_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/publish", requirements={"id" = "\d+"}, name="core_qa_question_publish")
	 */
	public function publishAction($id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertPublishable($question);

		// Publish
		$questionManager = $this->get(QuestionManager::NAME);
		$questionManager->publish($question);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('qa.question.form.alert.publish_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/unpublish", requirements={"id" = "\d+"}, name="core_qa_question_unpublish")
	 */
	public function unpublishAction(Request $request, $id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertUnpublishable($question);

		// Unpublish
		$questionManager = $this->get(QuestionManager::NAME);
		$questionManager->unpublish($question);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('qa.question.form.alert.unpublish_success', array( '%title%' => $question->getTitle() )));

		// Return to
		$returnToUrl = $request->get('rtu');
		if (is_null($returnToUrl)) {
			$returnToUrl = $request->headers->get('referer');
		}

		return $this->redirect($returnToUrl);
	}

	/**
	 * @Route("/{id}/edit", requirements={"id" = "\d+"}, name="core_qa_question_edit")
	 * @Template("LadbCoreBundle:Qa/Question:edit.html.twig")
	 */
	public function editAction($id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertEditabable($question);

		$form = $this->createForm(QuestionType::class, $question);

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'question'     => $question,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	/**
	 * @Route("/{id}/update", requirements={"id" = "\d+"}, methods={"POST"}, name="core_qa_question_update")
	 * @Template("LadbCoreBundle:Qa/Question:edit.html.twig")
	 */
	public function updateAction(Request $request, $id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertEditabable($question);

		$originalBodyBlocks = $question->getBodyBlocks()->toArray();	// Need to be an array to copy values
		$previouslyUsedTags = $question->getTags()->toArray();	// Need to be an array to copy values

		$question->resetBodyBlocks(); // Reset bodyBlocks array to consider form bodyBlocks order

		$form = $this->createForm(QuestionType::class, $question);
		$form->handleRequest($request);

		if ($form->isValid()) {

			$blockBodiedUtils = $this->get(BlockBodiedUtils::NAME);
			$blockBodiedUtils->preprocessBlocks($question, $originalBodyBlocks);

			$fieldPreprocessorUtils = $this->get(FieldPreprocessorUtils::NAME);
			$fieldPreprocessorUtils->preprocessFields($question);

			if ($question->getBodyBlockPictureCount() > 0) {
				$question->setMainPicture($blockBodiedUtils->getFirstPicture($question));
			}

			if ($question->getUser() == $this->getUser()) {
				$question->setUpdatedAt(new \DateTime());
			}

			$om = $this->getDoctrine()->getManager();
			$om->flush();

			// Dispatch publication event
			$dispatcher = $this->get('event_dispatcher');
			$dispatcher->dispatch(PublicationListener::PUBLICATION_UPDATED, new PublicationEvent($question, array( 'previouslyUsedTags' => $previouslyUsedTags )));

			// Flashbag
			$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('blog.post.form.alert.update_success', array( '%title%' => $question->getTitle() )));

			// Regenerate the form
			$form = $this->createForm(QuestionType::class, $question);

		} else {

			// Flashbag
			$this->get('session')->getFlashBag()->add('error', $this->get('translator')->trans('default.form.alert.error'));

		}

		$tagUtils = $this->get(TagUtils::NAME);

		return array(
			'question'     => $question,
			'form'         => $form->createView(),
			'tagProposals' => $tagUtils->getProposals($question),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_qa_question_delete")
	 */
	public function deleteAction($id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertDeletable($question);

		// Delete
		$questionManager = $this->get(QuestionManager::NAME);
		$questionManager->delete($question);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('blog.post.form.alert.delete_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_qa_question_list'));
	}

	/**
	 * @Route("/{id}/chown", requirements={"id" = "\d+"}, name="core_qa_question_chown")
	 */
	public function chownAction(Request $request, $id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertChownable($question);

		$targetUser = $this->retrieveOwner($request);

		// Change owner
		$questionManager = $this->get(QuestionManager::NAME);
		$questionManager->changeOwner($question, $targetUser);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('qa.question.form.alert.chown_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_qa_question_show', array( 'id' => $question->getSluggedId() )));
	}

	/**
	 * @Route("/{id}/widget", requirements={"id" = "\d+"}, name="core_qa_question_widget")
	 * @Template("LadbCoreBundle:Qa/Question:widget-xhr.html.twig")
	 */
	public function widgetAction($id) {

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertShowable($question, true);

		return array(
			'question' => $question,
		);
	}

	/**
	 * @Route("/", name="core_qa_question_list")
	 * @Route("/{page}", requirements={"page" = "\d+"}, name="core_qa_question_list_page")
	 * @Template("LadbCoreBundle:Qa/Question:list.html.twig")
	 */
	public function listAction(Request $request, $page = 0) {
		$searchUtils = $this->get(SearchUtils::NAME);

		// Elasticsearch paginiation limit
		if ($page > 624) {
			throw $this->createNotFoundException('Page limit reached (core_qa_question_list_page)');
		}

		$layout = $request->get('layout', 'view');

		$routeParameters = array();
		if ($layout != 'view') {
			$routeParameters['layout'] = $layout;
		}

		/////

		if ($page == 0 && $layout == 'view') {
			$om = $this->getDoctrine()->getManager();

			// Tip & Offer highlight

			$maybeUtils = $this->get(MaybeUtils::NAME);
			if ($maybeUtils->canDoIt(0, 10, 'tip')) {
				$tipRepository = $om->getRepository(Tip::CLASS_NAME);
				$highlightedTip = $tipRepository->findOneRandomByUser($this->getUser());
			} else if ($maybeUtils->canDoIt(0, 5, 'offer')) {
				$offerRepository = $om->getRepository(Offer::CLASS_NAME);
				$highlightedOffer = $offerRepository->findOneRandomByCategoryAndUser(Offer::CATEGORY_JOB, $this->getUser());
			}

		}

		$searchParameters = $searchUtils->searchPaginedEntities(
			$request,
			$page,
			function($facet, &$filters, &$sort, &$noGlobalFilters, &$couldUseDefaultSort) use ($searchUtils) {
				switch ($facet->name) {

					// Filters /////

					case 'mine':

						if ($this->get('security.authorization_checker')->isGranted('ROLE_USER')) {

							if ($facet->value == 'draft') {

								$filter = (new \Elastica\Query\BoolQuery())
									->addFilter(new \Elastica\Query\MatchPhrase('user.username', $this->getUser()->getUsername()))
									->addFilter(new \Elastica\Query\Range('visibility', array( 'lt' => HiddableInterface::VISIBILITY_PUBLIC )))
								;

							} else {

								$filter = new \Elastica\Query\MatchPhrase('user.username', $this->getUser()->getUsernameCanonical());
							}

							$filters[] = $filter;

							$couldUseDefaultSort = true;

						}

						break;

					case 'period':

						if ($facet->value == 'last7days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-7d/d' ));

						} elseif ($facet->value == 'last30days') {

							$filters[] = new \Elastica\Query\Range('createdAt', array( 'gte' => 'now-30d/d' ));

						}

						break;

					case 'tag':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'tags.label' ));
						$filters[] = $filter;

						break;

					case 'author':

						$filter = new \Elastica\Query\QueryString($facet->value);
						$filter->setFields(array( 'user.displayname', 'user.fullname', 'user.username'  ));
						$filters[] = $filter;

						break;

					case 'no-answer':

						$filter = new \Elastica\Query\Range('answerCount', array( 'lte' => 0 ));
						$filters[] = $filter;

						break;

					case 'without-positive-answer':

						$filter = new \Elastica\Query\Range('positiveAnswerCount', array( 'lte' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-positive-answer':

						$filter = new \Elastica\Query\Range('positiveAnswerCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-best-answer':

						$filter = new \Elastica\Query\QueryString('*');
						$filter->setFields(array( 'bestAnswer.body' ));
						$filters[] = $filter;

						break;

					case 'with-null-answer':

						$filter = new \Elastica\Query\Range('nullAnswerCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-undetermined-answer':

						$filter = new \Elastica\Query\Range('undeterminedAnswerCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'with-negative-answer':

						$filter = new \Elastica\Query\Range('negativeAnswerCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-creations':

						$filter = new \Elastica\Query\Range('creationCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-plans':

						$filter = new \Elastica\Query\Range('planCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					case 'content-howtos':

						$filter = new \Elastica\Query\Range('howtoCount', array( 'gt' => 0 ));
						$filters[] = $filter;

						break;

					// Sorters /////

					case 'sort-recent':
						$sort = array( 'changedAt' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-views':
						$sort = array( 'viewCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-likes':
						$sort = array( 'likeCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-comments':
						$sort = array( 'commentCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-popular-answers':
						$sort = array( 'answerCount' => array( 'order' => $searchUtils->getSorterOrder($facet) ) );
						break;

					case 'sort-random':
						$sort = array( 'randomSeed' => isset($facet->value) ? $facet->value : '' );
						break;

					/////

					default:
						if (is_null($facet->name)) {

							$filter = new \Elastica\Query\QueryString($facet->value);
							$filter->setFields(array( 'title^100', 'body', 'tags.label', 'answers.body' ));
							$filters[] = $filter;

							$couldUseDefaultSort = false;

						}

				}
			},
			function(&$filters, &$sort) {

				$sort = array( 'changedAt' => array( 'order' => 'desc' ) );

			},
			function(&$filters) {

                if (is_null($this->getUser())) {
                    $boolQuery = new BoolQuery();
                    $termQuery = new Term();
                    $termQuery->setTerm('_id', 9865);
                    $boolQuery->addMustNot($termQuery);
                    $filters[] = $boolQuery;
                }

				$this->pushGlobalVisibilityFilter($filters, true, true);

			},
			'fos_elastica.index.ladb.qa_question',
			\Ladb\CoreBundle\Entity\Qa\Question::CLASS_NAME,
			'core_qa_question_list_page',
			$routeParameters
		);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATIONS_LISTED, new PublicationsEvent($searchParameters['entities'], !$request->isXmlHttpRequest()));

		$parameters = array_merge($searchParameters, array(
			'questions'       => $searchParameters['entities'],
			'layout'          => $layout,
			'routeParameters' => $routeParameters,
		));

		if ($request->isXmlHttpRequest()) {
			if ($layout == 'choice') {
				return $this->render('LadbCoreBundle:Qa/Question:list-choice-xhr.html.twig', $parameters);
			} else {
				return $this->render('LadbCoreBundle:Qa/Question:list-xhr.html.twig', $parameters);
			}
		}

		if ($layout == 'choice') {
			return $this->render('LadbCoreBundle:Qa/Question:list-choice.html.twig', $parameters);
		}

		$parameters = array_merge($parameters, array(
			'highlightedTip'   => isset($highlightedTip) ? $highlightedTip : null,
			'highlightedOffer' => isset($highlightedOffer) ? $highlightedOffer : null,
		));

		if ($this->get('security.authorization_checker')->isGranted('ROLE_USER') && $this->getUser()->getMeta()->getPrivateQuestionCount() > 0) {

			$draftPath = $this->generateUrl('core_qa_question_list', array( 'q' => '@mine:draft' ));
			$draftCount = $this->getUser()->getMeta()->getPrivateQuestionCount();

			// Flashbag
			$this->get('session')->getFlashBag()->add('info', '<i class="ladb-icon-warning"></i> '.$this->get('translator')->transchoice('qa.question.choice.draft_alert', $draftCount, array( '%count%' => $draftCount )).' <small><a href="'.$draftPath.'" class="alert-link">('.$this->get('translator')->trans('default.show_my_drafts').')</a></small>');

		}

		return $parameters;
	}

	/**
	 * @Route("/{id}/creations", requirements={"id" = "\d+"}, name="core_qa_question_creations")
	 * @Route("/{id}/creations/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_qa_question_creations_filter")
	 * @Route("/{id}/creations/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_qa_question_creations_filter_page")
	 * @Template("LadbCoreBundle:Qa/Question:creations.html.twig")
	 */
	public function creationsAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertShowable($question);

		// Creations

		$creationRepository = $om->getRepository(Creation::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $creationRepository->findPaginedByQuestion($question, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_qa_question_creations_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'creations'   => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Creation:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'question' => $question,
		));
	}

	/**
	 * @Route("/{id}/plans", requirements={"id" = "\d+"}, name="core_qa_question_plans")
	 * @Route("/{id}/plans/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_qa_question_plans_filter")
	 * @Route("/{id}/plans/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_qa_question_plans_filter_page")
	 * @Template("LadbCoreBundle:Qa/Question:plans.html.twig")
	 */
	public function plansAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertShowable($question);

		// Plans

		$planRepository = $om->getRepository(Plan::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $planRepository->findPaginedByQuestion($question, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_qa_question_plans_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'plans'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Wonder/Plan:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'question' => $question,
		));
	}

	/**
	 * @Route("/{id}/pas-a-pas", requirements={"id" = "\d+"}, name="core_qa_question_howtos")
	 * @Route("/{id}/pas-a-pas/{filter}", requirements={"id" = "\d+", "filter" = "[a-z-]+"}, name="core_qa_question_howtos_filter")
	 * @Route("/{id}/pas-a-pas/{filter}/{page}", requirements={"id" = "\d+", "filter" = "[a-z-]+", "page" = "\d+"}, name="core_qa_question_howtos_filter_page")
	 * @Template("LadbCoreBundle:Qa/Question:howtos.html.twig")
	 */
	public function howtosAction(Request $request, $id, $filter = "recent", $page = 0) {
		$om = $this->getDoctrine()->getManager();

		$question = $this->retrievePublication($id, Question::CLASS_NAME);
		$this->assertShowable($question);

		// Howtos

		$howtoRepository = $om->getRepository(Howto::CLASS_NAME);
		$paginatorUtils = $this->get(PaginatorUtils::NAME);

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $howtoRepository->findPaginedByQuestion($question, $offset, $limit, $filter);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_qa_question_howtos_filter_page', array( 'id' => $id, 'filter' => $filter ), $page, $paginator->count());

		$parameters = array(
			'filter'      => $filter,
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'howtos'      => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('LadbCoreBundle:Howto/Howto:list-xhr.html.twig', $parameters);
		}

		return array_merge($parameters, array(
			'question' => $question,
		));
	}

	/**
	 * @Route("/{id}.html", name="core_qa_question_show")
	 * @Template("LadbCoreBundle:Qa/Question:show.html.twig")
	 */
	public function showAction(Request $request, $id) {
		$om = $this->getDoctrine()->getManager();
		$questionRepository = $om->getRepository(\Ladb\CoreBundle\Entity\Qa\Question::CLASS_NAME);
		$answerRepository = $om->getRepository(\Ladb\CoreBundle\Entity\Qa\Answer::CLASS_NAME);
		$witnessManager = $this->get(WitnessManager::NAME);

		$id = intval($id);

		$question = $questionRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($question)) {
			if ($response = $witnessManager->checkResponse(Question::TYPE, $id)) {
				return $response;
			}
			throw $this->createNotFoundException('Unable to find Question entity (id='.$id.').');
		}
		$this->assertShowable($question);

        if ($id == 9865 && is_null($this->getUser())) {
            throw $this->createNotFoundException('Mister hide.');
        }

		$sorter = 'score';
		$answers = $answerRepository->findByQuestion($question, $sorter);

		$user = $this->getUser();
		$userAnswer = null;
		if ($this->get('security.authorization_checker')->isGranted('ROLE_USER')) {
			foreach ($question->getAnswers() as $answer) {
				if ($answer->getUser()->getId() == $user->getId()) {
					$userAnswer = $answer;
					break;
				}
			}
		}

		$explorableUtils = $this->get(ExplorableUtils::NAME);
		$userQuestions = $explorableUtils->getPreviousAndNextPublishedUserExplorables($question, $questionRepository, $question->getUser()->getMeta()->getPublicQuestionCount());
		$similarQuestions = $explorableUtils->getSimilarExplorables($question, 'fos_elastica.index.ladb.qa_question', Question::CLASS_NAME, $userQuestions);

		// Dispatch publication event
		$dispatcher = $this->get('event_dispatcher');
		$dispatcher->dispatch(PublicationListener::PUBLICATION_SHOWN, new PublicationEvent($question));

		$likableUtils = $this->get(LikableUtils::NAME);
		$watchableUtils = $this->get(WatchableUtils::NAME);
		$commentableUtils = $this->get(CommentableUtils::NAME);
		$collectionnableUtils = $this->get(CollectionnableUtils::NAME);
		$followerUtils = $this->get(FollowerUtils::NAME);
		$votableUtils = $this->get(VotableUtils::NAME);

		return array(
			'question'          => $question,
			'permissionContext' => $this->getPermissionContext($question),
			'userQuestions'     => $userQuestions,
			'similarQuestions'  => $similarQuestions,
			'likeContext'       => $likableUtils->getLikeContext($question, $this->getUser()),
			'watchContext'      => $watchableUtils->getWatchContext($question, $this->getUser()),
			'commentContext'    => $commentableUtils->getCommentContext($question, false),
			'followerContext'   => $followerUtils->getFollowerContext($question->getUser(), $this->getUser()),
			'voteContexts'      => $votableUtils->getVoteContexts($question->getAnswers(), $this->getUser()),
			'commentContexts'   => $commentableUtils->getCommentContexts($question->getAnswers(), false),
			'collectionContext' => $collectionnableUtils->getCollectionContext($question),
			'answers'           => $answers,
			'userAnswer'        => $userAnswer,
			'sorter'            => $sorter,
		);
	}

	/**
	 * @Route("/{id}/admin/converttooffer", requirements={"id" = "\d+"}, name="core_qa_question_admin_converttooffer")
	 * @Security("is_granted('ROLE_ADMIN')", statusCode=404, message="Not allowed (core_qa_question_admin_converttooffer)")
	 */
	public function adminConvertToOfferAction($id) {
		$om = $this->getDoctrine()->getManager();
		$questionRepository = $om->getRepository(Question::CLASS_NAME);

		$question = $questionRepository->findOneByIdJoinedOnOptimized($id);
		if (is_null($question)) {
			throw $this->createNotFoundException('Unable to find Question entity (id='.$id.').');
		}

		// Convert
		$questionManager = $this->get(QuestionManager::NAME);
		$offer = $questionManager->convertToOffer($question);

		// Flashbag
		$this->get('session')->getFlashBag()->add('success', $this->get('translator')->trans('qa.question.admin.alert.converttooffer_success', array( '%title%' => $question->getTitle() )));

		return $this->redirect($this->generateUrl('core_offer_show', array( 'id' => $offer->getSluggedId() )));
	}

}
