<?php
namespace App\Enums;
enum InstallationStatus: string { case Pending='PENDING'; case Validating='VALIDATING'; case CreatingFolder='CREATING_FOLDER'; case CreatingDatabase='CREATING_DATABASE'; case AssigningDatabaseUser='ASSIGNING_DATABASE_USER'; case Registering='REGISTERING'; case TestingConnection='TESTING_CONNECTION'; case ReadyForDeploy='READY_FOR_DEPLOY'; case Ready='READY'; case Failed='FAILED'; }
