<?php

/**
 * @file classes/submission/sectionEditor/SectionEditorSubmissionDAO.inc.php
 *
 * Copyright (c) 2003-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SectionEditorSubmissionDAO
 * @ingroup submission
 * @see SectionEditorSubmission
 *
 * @brief Operations for retrieving and modifying SectionEditorSubmission objects.
 */

import('classes.submission.sectionEditor.SectionEditorSubmission');
import('classes.article.ArticleDAO');

import('classes.submission.reviewer.ReviewerSubmission');

class SectionEditorSubmissionDAO extends ArticleDAO {
	var $userDao;
	var $reviewAssignmentDao;
	var $articleFileDao;
	var $suppFileDao;
	var $signoffDao;
	var $galleyDao;
	var $articleEmailLogDao;
	var $submissionCommentDao;
	var $reviewRoundDao;

	/**
	 * Constructor.
	 */
	function SectionEditorSubmissionDAO() {
		parent::ArticleDAO();
		$this->userDao = DAORegistry::getDAO('UserDAO');
		$this->reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO');
		$this->articleFileDao = DAORegistry::getDAO('ArticleFileDAO');
		$this->suppFileDao = DAORegistry::getDAO('SuppFileDAO');
		$this->signoffDao = DAORegistry::getDAO('SignoffDAO');
		$this->galleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
		$this->articleEmailLogDao = DAORegistry::getDAO('SubmissionEmailLogDAO');
		$this->submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO');
		$this->reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
	}

	/**
	 * Instantiate a new data object.
	 * @return SectionEditorSubmission
	 */
	function newDataObject() {
		return new SectionEditorSubmission();
	}

	/**
	 * Retrieve a section editor submission by article ID.
	 * @param $articleId int
	 * @return SectionEditorSubmission
	 */
	function getSectionEditorSubmission($articleId) {
		$primaryLocale = AppLocale::getPrimaryLocale();
		$locale = AppLocale::getLocale();
		$result = $this->retrieve(
			'SELECT	a.*, pa.date_published,
				COALESCE(stl.setting_value, stpl.setting_value) AS section_title,
				COALESCE(sal.setting_value, sapl.setting_value) AS section_abbrev,
				r2.review_revision
			FROM	submissions a
				LEFT JOIN published_submissions pa ON (a.submission_id = pa.submission_id)
				LEFT JOIN sections s ON (s.section_id = a.section_id)
				LEFT JOIN review_rounds r2 ON (a.submission_id = r2.submission_id AND a.current_round = r2.round)
				LEFT JOIN section_settings stpl ON (s.section_id = stpl.section_id AND stpl.setting_name = ? AND stpl.locale = ?)
				LEFT JOIN section_settings stl ON (s.section_id = stl.section_id AND stl.setting_name = ? AND stl.locale = ?)
				LEFT JOIN section_settings sapl ON (s.section_id = sapl.section_id AND sapl.setting_name = ? AND sapl.locale = ?)
				LEFT JOIN section_settings sal ON (s.section_id = sal.section_id AND sal.setting_name = ? AND sal.locale = ?)
			WHERE	a.submission_id = ?',
			array(
				'title',
				$primaryLocale,
				'title',
				$locale,
				'abbrev',
				$primaryLocale,
				'abbrev',
				$locale,
				$articleId
			)
		);

		$returner = null;
		if ($result->RecordCount() != 0) {
			$returner = $this->_returnSectionEditorSubmissionFromRow($result->GetRowAssoc(false));
		}

		$result->Close();
		return $returner;
	}

	/**
	 * Internal function to return a SectionEditorSubmission object from a row.
	 * @param $row array
	 * @return SectionEditorSubmission
	 */
	function _returnSectionEditorSubmissionFromRow($row) {
		// Article attributes
		$sectionEditorSubmission = parent::_fromRow($row);

		// Editor Decisions
		$editDecisionDao = DAORegistry::getDAO('EditDecisionDAO');
		for ($i = 1; $i <= $row['current_round']; $i++) {
			$sectionEditorSubmission->setDecisions($editDecisionDao->getEditorDecisions($row['submission_id'], null, $i), $i);
		}

		// Comments
		$sectionEditorSubmission->setMostRecentEditorDecisionComment($this->submissionCommentDao->getMostRecentSubmissionComment($row['submission_id'], COMMENT_TYPE_EDITOR_DECISION, $row['submission_id']));
		$sectionEditorSubmission->setMostRecentCopyeditComment($this->submissionCommentDao->getMostRecentSubmissionComment($row['submission_id'], COMMENT_TYPE_COPYEDIT, $row['submission_id']));
		$sectionEditorSubmission->setMostRecentLayoutComment($this->submissionCommentDao->getMostRecentSubmissionComment($row['submission_id'], COMMENT_TYPE_LAYOUT, $row['submission_id']));
		$sectionEditorSubmission->setMostRecentProofreadComment($this->submissionCommentDao->getMostRecentSubmissionComment($row['submission_id'], COMMENT_TYPE_PROOFREAD, $row['submission_id']));

		// Files
		$sectionEditorSubmission->setSubmissionFile($this->articleFileDao->getArticleFile($row['submission_file_id']));
		$sectionEditorSubmission->setRevisedFile($this->articleFileDao->getArticleFile($row['revised_file_id']));
		$sectionEditorSubmission->setReviewFile($this->articleFileDao->getArticleFile($row['review_file_id']));
		$sectionEditorSubmission->setSuppFiles($this->suppFileDao->getSuppFilesByArticle($row['submission_id']));
		$sectionEditorSubmission->setEditorFile($this->articleFileDao->getArticleFile($row['editor_file_id']));


		for ($i = 1; $i <= $row['current_round']; $i++) {
			$sectionEditorSubmission->setEditorFileRevisions($this->articleFileDao->getArticleFileRevisions($row['editor_file_id'], $i), $i);
			$sectionEditorSubmission->setAuthorFileRevisions($this->articleFileDao->getArticleFileRevisions($row['revised_file_id'], $i), $i);
		}

		// Review Rounds
		$sectionEditorSubmission->setReviewRevision($row['review_revision']);

		// Review Assignments
		$reviewRounds = $this->reviewRoundDao->getBySubmissionId($row['submission_id']);
		while ($reviewRound = $reviewRounds->next()) {
			$round = $reviewRound->getRound();
			$sectionEditorSubmission->setReviewAssignments(
				$this->reviewAssignmentDao->getBySubmissionId($row['submission_id'], $round),
				$round
			);
		}

		// Layout Editing
		$sectionEditorSubmission->setGalleys($this->galleyDao->getGalleysByArticle($row['submission_id']));

		// Proof Assignment
		HookRegistry::call('SectionEditorSubmissionDAO::_returnSectionEditorSubmissionFromRow', array(&$sectionEditorSubmission, &$row));

		return $sectionEditorSubmission;
	}

	/**
	 * Update an existing section editor submission.
	 * @param $sectionEditorSubmission SectionEditorSubmission
	 */
	function updateSectionEditorSubmission(&$sectionEditorSubmission) {
		// Update editor decisions
		for ($i = 1; $i <= $sectionEditorSubmission->getCurrentRound(); $i++) {
			$editorDecisions =& $sectionEditorSubmission->getDecisions($i);
			if (is_array($editorDecisions)) {
				foreach ($editorDecisions as $key => $editorDecision) {
					if ($editorDecision['editDecisionId'] == null) {
						$this->update(
							sprintf('INSERT INTO edit_decisions
								(submission_id, round, editor_id, decision, date_decided)
								VALUES (?, ?, ?, ?, %s)',
								$this->datetimeToDB($editorDecision['dateDecided'])),
							array($sectionEditorSubmission->getId(), $sectionEditorSubmission->getCurrentRound(), $editorDecision['editorId'], $editorDecision['decision'])
						);
						$editorDecisions[$key]['editDecisionId'] = $this->_getInsertId('edit_decisions', 'edit_decision_id');
					}
				}
			}
			unset($editorDecisions);
		}
		if ($this->reviewRoundExists($sectionEditorSubmission->getId(), $sectionEditorSubmission->getCurrentRound())) {
			$this->update(
				'UPDATE review_rounds
					SET
						review_revision = ?
					WHERE submission_id = ? AND round = ?',
				array(
					$sectionEditorSubmission->getReviewRevision(),
					$sectionEditorSubmission->getId(),
					$sectionEditorSubmission->getCurrentRound()
				)
			);
		} elseif ($sectionEditorSubmission->getReviewRevision()!=null) {
			$this->createReviewRound(
				$sectionEditorSubmission->getId(),
				$sectionEditorSubmission->getCurrentRound() === null ? 1 : $sectionEditorSubmission->getCurrentRound(),
				$sectionEditorSubmission->getReviewRevision()
			);
		}

		// update review assignments
		foreach ($sectionEditorSubmission->getReviewAssignments() as $roundReviewAssignments) {
			foreach ($roundReviewAssignments as $reviewAssignment) {
				if ($reviewAssignment->getId() > 0) {
					$this->reviewAssignmentDao->updateObject($reviewAssignment);
				} else {
					$this->reviewAssignmentDao->insertObject($reviewAssignment);
				}
			}
		}

		// Remove deleted review assignments
		$removedReviewAssignments = $sectionEditorSubmission->getRemovedReviewAssignments();
		for ($i=0, $count=count($removedReviewAssignments); $i < $count; $i++) {
			$this->reviewAssignmentDao->deleteById($removedReviewAssignments[$i]);
		}

		// Update article
		if ($sectionEditorSubmission->getId()) {

			$article = parent::getById($sectionEditorSubmission->getId());

			// Only update fields that can actually be edited.
			$article->setSectionId($sectionEditorSubmission->getSectionId());
			$article->setCurrentRound($sectionEditorSubmission->getCurrentRound());
			$article->setReviewFileId($sectionEditorSubmission->getReviewFileId());
			$article->setEditorFileId($sectionEditorSubmission->getEditorFileId());
			$article->setStatus($sectionEditorSubmission->getStatus());
			$article->setDateStatusModified($sectionEditorSubmission->getDateStatusModified());
			$article->setLastModified($sectionEditorSubmission->getLastModified());
			$article->setCommentsStatus($sectionEditorSubmission->getCommentsStatus());

			parent::updateObject($article);
		}

	}

	function createReviewRound($articleId, $round, $reviewRevision) {
		$this->update(
			'INSERT INTO review_rounds
				(submission_id, round, review_revision)
				VALUES
				(?, ?, ?)',
			array($articleId, $round, $reviewRevision)
		);
	}

	/**
	 * FIXME Move this into somewhere common (SubmissionDAO?) as this is used in several classes.
	 */
	function _generateUserNameSearchSQL($search, $searchMatch, $prefix, &$params) {
		$first_last = $this->concat($prefix.'first_name', '\' \'', $prefix.'last_name');
		$first_middle_last = $this->concat($prefix.'first_name', '\' \'', $prefix.'middle_name', '\' \'', $prefix.'last_name');
		$last_comma_first = $this->concat($prefix.'last_name', '\', \'', $prefix.'first_name');
		$last_comma_first_middle = $this->concat($prefix.'last_name', '\', \'', $prefix.'first_name', '\' \'', $prefix.'middle_name');
		if ($searchMatch === 'is') {
			$searchSql = " AND (LOWER({$prefix}last_name) = LOWER(?) OR LOWER($first_last) = LOWER(?) OR LOWER($first_middle_last) = LOWER(?) OR LOWER($last_comma_first) = LOWER(?) OR LOWER($last_comma_first_middle) = LOWER(?))";
		} elseif ($searchMatch === 'contains') {
			$searchSql = " AND (LOWER({$prefix}last_name) LIKE LOWER(?) OR LOWER($first_last) LIKE LOWER(?) OR LOWER($first_middle_last) LIKE LOWER(?) OR LOWER($last_comma_first) LIKE LOWER(?) OR LOWER($last_comma_first_middle) LIKE LOWER(?))";
			$search = '%' . $search . '%';
		} else { // $searchMatch === 'startsWith'
			$searchSql = " AND (LOWER({$prefix}last_name) LIKE LOWER(?) OR LOWER($first_last) LIKE LOWER(?) OR LOWER($first_middle_last) LIKE LOWER(?) OR LOWER($last_comma_first) LIKE LOWER(?) OR LOWER($last_comma_first_middle) LIKE LOWER(?))";
			$search = $search . '%';
		}
		$params[] = $params[] = $params[] = $params[] = $params[] = $search;
		return $searchSql;
	}

	//
	// Miscellaneous
	//

	/**
	 * Check if a review round exists for a specified article.
	 * @param $articleId int
	 * @param $round int
	 * @return boolean
	 */
	function reviewRoundExists($articleId, $round) {
		$result = $this->retrieve(
			'SELECT COUNT(*) FROM review_rounds WHERE submission_id = ? AND round = ?', array($articleId, $round)
		);
		$returner = isset($result->fields[0]) && $result->fields[0] == 1 ? true : false;

		$result->Close();
		return $returner;
	}

	/**
	 * Map a column heading value to a database value for sorting
	 * @param string
	 * @return string
	 */
	function getSortMapping($heading) {
		switch ($heading) {
			case 'id': return 'a.submission_id';
			case 'submitDate': return 'a.date_submitted';
			case 'section': return 'section_abbrev';
			case 'authors': return 'author_name';
			case 'title': return 'submission_title';
			case 'active': return 'incomplete';
			case 'reviewerName': return 'u.last_name';
			case 'quality': return 'average_quality';
			case 'done': return 'completed';
			case 'latest': return 'latest';
			case 'active': return 'active';
			case 'average': return 'average';
			case 'name': return 'u.last_name';
			default: return null;
		}
	}
}

?>
