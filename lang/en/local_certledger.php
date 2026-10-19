<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English strings.
 *
 * @package    local_certledger
 * @copyright  2026 Mediaform <https://www.media-form.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['all'] = 'All';
$string['annul'] = 'Annul';
$string['annulcertificate'] = 'Annul certificate {$a}';
$string['annulled'] = 'Certificate {$a} annulled.';
$string['annulreason'] = 'Reason for the annulment';
$string['annulwarning'] = 'The certificate will be marked as annulled in the ledger and deleted from the site, including any copy made by a course restore: the learner will no longer be able to download it and its code will no longer be verifiable. The number is never reused.';
$string['anonymised'] = 'Anonymised data';
$string['applyfilters'] = 'Filter';
$string['backtoregister'] = 'Back to the ledger';
$string['blocked'] = 'Blocked';
$string['blockedinfo'] = 'The certificate is annulled and the learner cannot receive it again from this activity. Lifting the block lets Custom certificate issue a new certificate, with a new number, if the learner meets the requirements.';
$string['blockreissue'] = 'Prevent a new issue to the learner';
$string['blockreissue_desc'] = 'Recommended. Without the block, Custom certificate issues a new certificate with a new number as soon as the learner opens the activity again, or within a minute when issuing is automatic. The block can be lifted from the ledger.';
$string['blockroledesc'] = 'Technical role of Certificate Ledger: assigned in the activity to a learner whose certificate was annulled, it stops Custom certificate from issuing another one. Do not assign it manually.';
$string['blockrolename'] = 'Certificate blocked (Certificate Ledger)';
$string['certcode'] = 'Code on the certificate';
$string['certledger:annul'] = 'Annul certificates in the ledger';
$string['certledger:view'] = 'View and export the certificate ledger';
$string['certname'] = 'Certificate';
$string['certnumber'] = 'Number';
$string['codegenerationfailed'] = 'Could not generate a unique certificate code.';
$string['cohorts'] = 'Cohorts';
$string['confirmannul'] = 'Confirm the annulment';
$string['digits'] = 'Digits';
$string['digits_desc'] = 'Minimum number of digits of the sequential number, padded with zeros (e.g. 6 digits: 000001). Changing it once numbering has started only changes how the following numbers are written.';
$string['downloadpdf'] = 'PDF';
$string['downloadpdfof'] = 'Download the PDF of certificate {$a}';
$string['eventcertificateannulled'] = 'Certificate annulled';
$string['eventnumberassigned'] = 'Certificate number assigned';
$string['eventregisterexported'] = 'Certificate ledger exported';
$string['eventregisterviewed'] = 'Certificate ledger viewed';
$string['filteryear'] = 'Year';
$string['learner'] = 'Learner';
$string['locktimeout'] = 'Could not obtain the numbering lock, please try again.';
$string['nocertificates'] = 'No certificate matches.';
$string['notvalid'] = 'This certificate is no longer valid and cannot be annulled.';
$string['numbering'] = 'Numbering';
$string['numbering_desc'] = 'Every certificate issued by a Custom certificate activity receives a sequential number when it is issued. The number is recorded in the ledger and printed on the certificate by the "Code" and "QR code" elements of the template. Changing these settings only affects the certificates issued afterwards.';
$string['pluginname'] = 'Certificate Ledger';
$string['prefix'] = 'Prefix';
$string['prefix_desc'] = '1 to 10 letters and digits, at least one letter (e.g. CERT); stored in uppercase. Changing the prefix starts a new number series: the numbers already assigned stay valid.';
$string['privacy:metadata:certcode'] = 'The code printed on the certificate.';
$string['privacy:metadata:certname'] = 'The name of the certificate.';
$string['privacy:metadata:certnumber'] = 'The number of the certificate.';
$string['privacy:metadata:cmid'] = 'The activity (course module) that issued the certificate.';
$string['privacy:metadata:cohorts'] = 'The cohorts the learner belonged to when the certificate was issued.';
$string['privacy:metadata:courseid'] = 'The course of the certificate.';
$string['privacy:metadata:coursename'] = 'The name of the course when the certificate was issued.';
$string['privacy:metadata:customcertid'] = 'The Custom certificate activity that issued the certificate.';
$string['privacy:metadata:email'] = 'The email address of the learner when the certificate was issued.';
$string['privacy:metadata:firstname'] = 'The first name of the learner when the certificate was issued.';
$string['privacy:metadata:issueid'] = 'The identifier of the issue in Custom certificate.';
$string['privacy:metadata:lastname'] = 'The last name of the learner when the certificate was issued.';
$string['privacy:metadata:origcode'] = 'The random code generated by Custom certificate before numbering.';
$string['privacy:metadata:status'] = 'Whether the certificate is valid, annulled or removed from the site.';
$string['privacy:metadata:statusby'] = 'The user who annulled or removed the certificate.';
$string['privacy:metadata:statusreason'] = 'The reason of the annulment.';
$string['privacy:metadata:table'] = 'The ledger of the numbered certificates, with a copy of the learner and course data taken when each certificate was issued. By default it is kept intact as a record; anonymisation on deletion requests can be enabled in the plugin settings.';
$string['privacy:metadata:timeissued'] = 'When the certificate was issued.';
$string['privacy:metadata:timestatus'] = 'When the certificate was annulled or removed.';
$string['privacy:metadata:userid'] = 'The learner who received the certificate.';
$string['privacyanonymise'] = 'Anonymise on deletion requests';
$string['privacyanonymise_desc'] = 'If enabled (default), an approved data deletion request (Privacy and policies) anonymises first name, last name, email and cohorts in the ledger, keeping number, course, dates and status. If disabled, the ledger is kept intact as a record of the certificates issued, for example when the law requires it: state this in the site privacy notice.';
$string['privacyheading'] = 'Privacy';
$string['register'] = 'Certificate ledger';
$string['registerempty'] = 'No certificate recorded yet. Numbers are assigned when certificates are issued.';
$string['registerintro'] = 'Every certificate receives a number such as {$a->number}; the code printed on the certificate is {$a->code}, where the final characters prevent anyone from finding other certificates by trying consecutive numbers. Numbering active since {$a->since}.';
$string['reissuewarning'] = 'This activity issues certificates automatically (automatic issuing or student emails): without the block the learner would receive a new certificate within a minute.';
$string['resetfilters'] = 'Reset';
$string['searchplaceholder'] = 'Number, code, name or email';
$string['settings'] = 'Certificate Ledger settings';
$string['status'] = 'Status';
$string['status_annulled'] = 'Annulled';
$string['status_removed'] = 'Removed from the site';
$string['status_valid'] = 'Valid';
$string['statusannulledinfo'] = 'Annulled by {$a->by} on {$a->date}: {$a->reason}';
$string['statusremovedbyinfo'] = 'Removed by {$a->by} on {$a->date}';
$string['statusremovedinfo'] = 'Removed on {$a->date}';
$string['suffixlength'] = 'Check characters';
$string['suffixlength_desc'] = 'Random characters appended to the code printed on the certificate (e.g. CERT-2026-000001-K7QXM). They prevent anyone from finding other people\'s certificates by trying consecutive numbers on the public verification page. Recommended: 5.';
$string['suffixnone'] = 'None (not recommended)';
$string['summaryannulled'] = 'Annulled: {$a}';
$string['summaryremoved'] = 'Removed: {$a}';
$string['summarytotal'] = 'Total: {$a}';
$string['summaryvalid'] = 'Valid: {$a}';
$string['taskreconcile'] = 'Certificate ledger reconciliation';
$string['timeissued'] = 'Issued on';
$string['unblock'] = 'Lift the block';
$string['unblocked'] = 'Block lifted for certificate {$a}.';
$string['warninglasterrors'] = 'The last reconciliation ({$a}) reported problems:';
$string['warningunregistered'] = 'Issued certificates waiting for a number: {$a}. The scheduled reconciliation numbers them within 10 minutes; if the warning persists, check the log of the "Certificate ledger reconciliation" task.';
$string['yearlyreset'] = 'Restart every year';
$string['yearlyreset_desc'] = 'If enabled, the year is part of the number (e.g. CERT-2026-000001) and numbering restarts from 1 every year. Changing it once numbering has started opens a new series: the numbers already assigned do not change.';
