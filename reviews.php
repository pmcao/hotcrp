<?php 
require_once('Code/confHeader.inc');
$Conf->connect();
$Me = $_SESSION["Me"];
$Me->goIfInvalid();

function doGoPaper() {
    echo "<div class='gopaper'>", goPaperForm(1), "</div><div class='clear'></div>\n\n";
}
    
function initialError($what, &$tf = null) {
    global $Conf, $paperId;
    if ($tf == null) {
	$title = ($paperId <= 0 ? "Review Papers" : "Review Paper #$paperId");
	$Conf->header($title, 'review');
	doGoPaper();
	$Conf->errorMsgExit($what);
    } else {
	$tf['err'][] = $tf['firstLineno'] . ": $what";
	return null;
    }
}

function get_prow($paperIdIn, &$tf = null) {
    global $Conf, $prow, $Me;

    if (($paperId = cvtint(trim($paperIdIn))) <= 0)
	return ($prow = initialError("Bad paper ID \"" . htmlentities($paperIdIn) . "\".", $tf));
    
    $result = $Conf->qe($Conf->paperQuery($Me->contactId, array("paperId" => $paperId)), "while requesting paper to review");
    if (DB::isError($result) || $result->numRows() == 0)
	$prow = initialError("No such paper #$paperId.", $tf);
    else {
	$prow = $result->fetchRow(DB_FETCHMODE_OBJECT);
	if (!$Me->canStartReview($prow, $Conf, $whyNot))
	    $prow = initialError(whyNotText($whyNot, "review", $prow->paperId), $tf);
    }
}

function get_rrow($paperId, $reviewId = -1, $tf = null) {
    global $Conf, $rrow, $Me, $reviewOrdinal;

    $q = "select PaperReview.*, firstName, lastName, email,
		count(PRS.reviewId) as reviewOrdinal
		from PaperReview
		join ContactInfo using (contactId)
		left join PaperReview as PRS on (PRS.paperId=PaperReview.paperId and PRS.reviewSubmitted>0 and PRS.reviewSubmitted<PaperReview.reviewSubmitted)";
    if ($reviewId > 0)
	$q = "$q where PaperReview.reviewId=$reviewId";
    else
	$q = "$q where PaperReview.paperId=$paperId and PaperReview.contactId=$Me->contactId";
    $result = $Conf->qe("$q group by PRS.paperId", "while retrieving reviews");

    if (DB::isError($result) || $result->numRows() == 0) {
	if ($reviewId > 0)
	    initialError("No such paper review #$reviewId.", $tf);
	$rrow = null;
    } else {
	$rrow = $result->fetchRow(DB_FETCHMODE_OBJECT);
	$_REQUEST['reviewId'] = $rrow->reviewId;
    }
}

$rf = reviewForm();

$originalPaperId = cvtint($_REQUEST["paperId"]);

if (isset($_REQUEST["form"]) && $_REQUEST["form"] && !count($_POST))
    $Conf->errorMsg("It looks like you tried to upload a gigantic file, larger than I can accept.  Any changes were lost.");

if (isset($_REQUEST['uploadForm']) && fileUploaded($_FILES['uploadedFile'], $Conf)) {
    $tf = $rf->beginTextForm($_FILES['uploadedFile']['tmp_name'], $_FILES['uploadedFile']['name']);
    $paperId = $originalPaperId;
    while ($rf->parseTextForm($tf, $originalPaperId, $Conf)) {
	get_prow($_REQUEST['paperId'], $tf);
	get_rrow($_REQUEST['paperId'], -1, $tf);
	if ($prow != null && $rf->validateRequest($rrow, 0, $tf)) {
	    $result = $rf->saveRequest($prow, $Me->contactId, $rrow, 0);
	    if (!DB::isError($result))
		$tf['confirm'][] = "Uploaded review for paper #$prow->paperId.";
	}
	$paperId = -1;
    }
    $rf->parseTextFormErrors($tf, $Conf);
    if (isset($_REQUEST['redirect']) && $_REQUEST['redirect'] == 'offline')
	go("OfflineReview.php");
 }

$paperId = $originalPaperId;
if (isset($_REQUEST["reviewId"])) {
    get_rrow(-1, cvtint(trim($_REQUEST["reviewId"])));
    if ($Me->contactId != $rrow->contactId && !$Me->amAssistant())
	initialError("You did not create review #$rrow->reviewId, so you cannot edit it.");
    $paperId = $rrow->paperId;
    get_prow($paperId);
} else if ($paperId > 0) {
    get_prow($paperId);
    get_rrow($paperId);
} else
    $prow = $rrow = null;

if (isset($_REQUEST['downloadForm'])) {
    $isReviewer = ($Me->isReviewer || $Me->isPC);
    $x = $rf->textFormHeader($Conf);
    $x .= $rf->textForm($paperId, $Conf, $prow, $rrow, $isReviewer, $isReviewer);
    header("Content-Description: PHP Generated Data");
    header("Content-Disposition: attachment; filename=" . $Conf->downloadPrefix . "review" . ($paperId > 0 ? "-$paperId.txt" : ".txt"));
    header("Content-Type: text/plain");
    header("Content-Length: " . strlen($x));
    print $x;
    exit;
 }

$title = ($paperId > 0 ? "Review Paper #$paperId" : "Review Papers");
$Conf->header($title, 'review');
doGoPaper();

if ($paperId <= 0) {
    $Conf->errorMsg("No paper selected to review.");
    $Conf->footer();
    exit;
 }

if (isset($_REQUEST['update']) || isset($_REQUEST['submit']))
    if ($rf->validateRequest($rrow, isset($_REQUEST['submit']))) {
	$rf->saveRequest($prow, $Me->contactId, $rrow, isset($_REQUEST['submit']), $Conf);
	$Conf->confirmMsg(isset($_REQUEST['submit']) ? "Review submitted." : "Review saved.");
	get_rrow($paperId);
    }

$overrideMsg = '';
if ($Me->amAssistant())
    $overrideMsg = "  Select the \"Override deadlines\" checkbox and try again if you really want to override this deadline.";

if (!$Me->timeReview($prow, $Conf))
    $Conf->infoMsg("The <a href='${ConfSiteBase}deadlines.php'>deadline</a> for modifying this review has passed.");

?>

<table class='revtop'>
<tr class='id'>
  <td class='caption'><h2>Review<?php
	if ($rrow && $rrow->reviewSubmitted > 0)
	    echo " ", chr(65 + $rrow->reviewOrdinal);
  ?></h2></td>
  <td class='entry'><h2>for <a href='paper.php?paperId=<?php echo $paperId ?>'>Paper #<?php echo $paperId ?></a></h2></td>
</tr>

<?php if (isset($rrow) && $Me->contactId != $rrow->contactId) { ?>
<tr class='rev_type'>
  <td class='caption'>Reviewer</td>
  <td class='entry'><?php echo htmlspecialchars(contactText($rrow)) ?></td>
</tr>
<?php } ?>
								
<tr class='rev_type'>
  <td class='caption'>Review type</td>
  <td class='entry'><?php echo reviewType($paperId, $prow, true) ?></td>
</tr>

<tr class='rev_status'>
  <td class='caption'>Review status</td>
  <td class='entry'><?php echo reviewStatus((isset($rrow) ? $rrow : $prow), true, true) ?></td>
</tr>

<tr class='rev_download'>
  <td class='caption'>Offline reviewing</td>
  <td class='entry'>
    <form class='downloadreviewform' action='ReviewPaper.php' method='get'>
      <input type='hidden' name='paperId' value='<?php echo $paperId ?>' />
      <input class='button_small' type='submit' value='Download review' name='downloadForm' id='downloadForm' />
    </form>
  </td>
</tr>
<tr class='rev_upload'>
  <td class='caption'></td>
  <td class='entry'>
    <form class='downloadreviewform' action='ReviewPaper.php?form=1' method='post' enctype='multipart/form-data'>
      <input type='hidden' name='paperId' value='<?php echo $paperId ?>' />
      <input type='file' name='uploadedFile' accept='text/plain' size='30' />&nbsp;<input class='button_small' type='submit' value='Upload review' name='uploadForm' />
    </form>
  </td>
</tr>
</table>


<table class='auview'>
<tr>
  <td class='caption'>#<?php echo $paperId ?></td>
  <td class='entry pt_title'><?php echo htmlspecialchars($prow->title) ?></td>
</tr>

<tr>
  <td class='caption'>Status</td>
  <td class='entry'><?php echo $Me->paperStatus($paperId, $prow, 1) ?></td>
</tr>

<?php if ($prow->withdrawn <= 0 && $prow->size > 0) { ?>
<tr>
  <td class='caption'>Paper</td>
  <td class='entry'><?php echo paperDownload($paperId, $prow, 1) ?></td>
</tr>
<?php } ?>

<tr class='pt_abstract'>
  <td class='caption'>Abstract</td>
  <td class='entry'><?php echo htmlFold(htmlspecialchars($prow->abstract), 25) ?></td>
</tr>

<?php if ($Me->canViewAuthors($prow, $Conf)) { ?>
<tr class='pt_authors'>
  <td class='caption'>Authors</td>
  <td class='entry'><?php echo authorTable($prow->authorInformation) ?></td>
</tr>

<tr class='pt_collaborators'>
  <td class='caption'>Collaborators</td>
  <td class='entry'><?php echo authorTable($prow->collaborators) ?></td>
</tr>
<?php } ?>

<?php
if ($topicTable = topicTable($paperId, -1)) { 
    echo "<tr class='pt_topics'>
  <td class='caption'>Topics</td>
  <td class='entry' id='topictable'>", $topicTable, "</td>
</tr>\n";
 }
?>
</table>

<hr class='clear' />

<form action='ReviewPaper.php?form=1' method='post' enctype='multipart/form-data'>
<?php
    if (isset($rrow))
	echo "<input type='hidden' name='reviewId' value='$rrow->reviewId' />\n";
    else 
	echo "<input type='hidden' name='paperId' value='$paperId' />\n";
?>
<table class='reviewform'>
<?php
echo $rf->webFormRows($rrow, 1);

if ($Me->timeReview($prow, $Conf) || $Me->amAssistant()) {
    echo "<tr class='rev_actions'>
  <td class='caption'></td>
  <td class='entry'><table class='pt_buttons'>
    <tr>\n";
    if (!isset($rrow) || !$rrow->reviewSubmitted) {
	echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Save changes' name='update' /></td>
      <td class='ptb_button'><input class='button_default' type='submit' value='Submit' name='submit' /></td>
    </tr>
    <tr>
      <td class='ptb_explain'>(does not submit review)</td>
      <td class='ptb_explain'>(allow PC to see review)</td>\n";
    } else
	echo "      <td class='ptb_button'><input class='button_default' type='submit' value='Resubmit' name='submit' /></td>\n";
    if (!$Me->timeReview($prow, $Conf))
	echo "    </tr>\n    <tr>\n      <td colspan='3'><input type='checkbox' name='override' value='1' />&nbsp;Override&nbsp;deadlines</td>\n";
    echo "    </tr>\n  </table></td>\n</tr>\n\n";
 } ?>

</table>
</form>

<?php $Conf->footer() ?>
