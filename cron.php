<?php
/**
 * ownCloud
 *
 * @author Jakob Sack
 * @copyright 2012 Jakob Sack owncloud@jakobsack.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

try {

	require_once 'lib/base.php';

	if (\OCP\Util::needUpgrade()) {
		\OCP\Util::writeLog('cron', 'Update required, skipping cron', \OCP\Util::DEBUG);
		exit();
	}

	// load all apps to get all api routes properly setup
	OC_App::loadApps();

	\OC::$server->getSession()->close();

	// initialize a dummy memory session
	\OC::$server->setSession(new \OC\Session\Memory(''));

	$logger = \OC_Log::$object;

	// Don't do anything if ownCloud has not been installed
	if (!OC_Config::getValue('installed', false)) {
		exit(0);
	}

	\OC::$server->getTempManager()->cleanOld();

	// Exit if background jobs are disabled!
	$appMode = OC_BackgroundJob::getExecutionType();
	if ($appMode == 'none') {
		if (OC::$CLI) {
			echo 'Background Jobs are disabled!' . PHP_EOL;
		} else {
			OC_JSON::error(array('data' => array('message' => 'Background jobs disabled!')));
		}
		exit(1);
	}

	if (OC::$CLI) {
		// set to run indefinitely if needed
		set_time_limit(0);

		$config = OC::$server->getConfig();
		$instanceId = $config->getSystemValue('instanceid');
		$lockFileName = 'owncloud-server-' . $instanceId . '-cron.lock';
		$lockDirectory = $config->getSystemValue('cron.lockfile.location', sys_get_temp_dir());
		$lockDirectory = rtrim($lockDirectory, '\\/');
		$lockFile = $lockDirectory . '/' . $lockFileName;

		if (!file_exists($lockFile)) {
			touch($lockFile);
		}

		// We call ownCloud from the CLI (aka cron)
		if ($appMode != 'cron') {
			OC_BackgroundJob::setExecutionType('cron');
		}

		// open the file and try to lock if. If it is not locked, the background
		// job can be executed, otherwise another instance is already running
		$fp = fopen($lockFile, 'w');
		$isLocked = flock($fp, LOCK_EX|LOCK_NB, $wouldBlock);

		// check if backgroundjobs is still running. The wouldBlock check is
		// needed on systems with advisory locking, see
		// http://php.net/manual/en/function.flock.php#45464
		if (!$isLocked || $wouldBlock) {
			echo "Another instance of cron.php is still running!" . PHP_EOL;
			exit(1);
		}

		// Work
		$jobList = \OC::$server->getJobList();
		$jobs = $jobList->getAll();
		foreach ($jobs as $job) {
			$job->execute($jobList, $logger);
		}

		// unlock the file
		flock($fp, LOCK_UN);
		fclose($fp);

	} else {
		// We call cron.php from some website
		if ($appMode == 'cron') {
			// Cron is cron :-P
			OC_JSON::error(array('data' => array('message' => 'Backgroundjobs are using system cron!')));
		} else {
			// Work and success :-)
			$jobList = \OC::$server->getJobList();
			$job = $jobList->getNext();
			if ($job != null) {
				$job->execute($jobList, $logger);
				$jobList->setLastJob($job);
			}
			OC_JSON::success();
		}
	}

	// Log the successful cron execution
	if (\OC::$server->getConfig()->getSystemValue('cron_log', true)) {
		\OC::$server->getConfig()->setAppValue('core', 'lastcron', time());
	}
	exit();

} catch (Exception $ex) {
	\OCP\Util::writeLog('cron', $ex->getMessage(), \OCP\Util::FATAL);
}
