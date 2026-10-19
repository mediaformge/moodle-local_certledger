@local @local_certledger
Feature: Number the issued certificates and keep them in a ledger
  In order to know which certificates were issued, to whom, and whether they are still valid
  As a manager
  I need every certificate to receive a sequential number, printed on it and recorded in a ledger

  Background:
    Given the following "courses" exist:
      | fullname         | shortname | category |
      | Workplace safety | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Ada       | Lovelace | ada@example.com      |
      | student2 | Alan      | Turing   | alan@example.com     |
      | teacher1 | Grace     | Hopper   | grace@example.com    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity   | name               | course | idnumber    | verifyany |
      | customcert | Safety certificate | C1     | customcert1 | 1         |
    And the following config values are set as admin:
      | prefix       | SAFE | local_certledger |
      | yearlyreset  | 0    | local_certledger |
      | suffixlength | 0    | local_certledger |

  Scenario: A certificate is numbered when the learner gets it, and the number verifies it
    When I log in as "student1"
    And I am on "Workplace safety" course homepage
    And I follow "Safety certificate"
    And I press "View certificate"
    And I log out
    And I visit the verification url for the "Safety certificate" certificate
    And I set the field "Code" to "SAFE-000001"
    And I press "Verify"
    Then I should see "Verified"
    And I should not see "Not verified"
    And I log in as "admin"
    And I navigate to "Reports > Certificate ledger" in site administration
    And the following should exist in the "local-certledger-table" table:
      | Number      | Code on the certificate | Last name | First name | Course           | Certificate        | Status |
      | SAFE-000001 | SAFE-000001             | Lovelace  | Ada        | Workplace safety | Safety certificate | Valid  |

  Scenario: A manager searches the ledger
    Given the following "local_certledger > issues" exist:
      | certificate        | user     |
      | Safety certificate | student1 |
      | Safety certificate | student2 |
    When I log in as "admin"
    And I navigate to "Reports > Certificate ledger" in site administration
    Then I should see "Total: 2"
    And I set the field "local-certledger-search" to "turing alan"
    And I press "Filter"
    And I should see "SAFE-000002" in the "local-certledger-table" "table"
    And I should not see "SAFE-000001" in the "local-certledger-table" "table"

  Scenario: A manager annuls a certificate and later lifts the block on a new issue
    Given the following "local_certledger > issues" exist:
      | certificate        | user     |
      | Safety certificate | student1 |
      | Safety certificate | student2 |
    When I log in as "admin"
    And I navigate to "Reports > Certificate ledger" in site administration
    And I click on "Annul" "link" in the "Turing" "table_row"
    And I set the field "Reason for the annulment" to "Issued by mistake"
    And I press "Confirm the annulment"
    Then I should see "Certificate SAFE-000002 annulled."
    And "Turing" row "Status" column of "local-certledger-table" table should contain "Annulled"
    And "Turing" row "Status" column of "local-certledger-table" table should contain "Blocked"
    And "Turing" row "Status" column of "local-certledger-table" table should contain "Issued by mistake"
    And "Lovelace" row "Status" column of "local-certledger-table" table should contain "Valid"
    And I log out
    And I visit the verification url for the "Safety certificate" certificate
    And I set the field "Code" to "SAFE-000002"
    And I press "Verify"
    And I should see "Not verified"
    And I log in as "admin"
    And I navigate to "Reports > Certificate ledger" in site administration
    And I click on "Lift the block" "link" in the "Turing" "table_row"
    And I press "Lift the block"
    And I should see "Block lifted for certificate SAFE-000002."
    And "Turing" row "Status" column of "local-certledger-table" table should not contain "Blocked"

  Scenario: A manager without the annul capability can only view the ledger
    Given the following "local_certledger > issues" exist:
      | certificate        | user     |
      | Safety certificate | student1 |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | manager1 | Mary      | Manager  | manager1@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    And the following "permission overrides" exist:
      | capability             | permission | role    | contextlevel | reference |
      | local/certledger:annul | Prohibit   | manager | System       |           |
    When I log in as "manager1"
    And I navigate to "Reports > Certificate ledger" in site administration
    Then "Lovelace" row "Number" column of "local-certledger-table" table should contain "SAFE-000001"
    And "Annul" "link" should not exist in the "Lovelace" "table_row"
