<?php

declare(strict_types=1);

namespace SecurityDrama\Pipeline;

use SecurityDrama\Config;
use SecurityDrama\Database;
use SecurityDrama\Logger;
use SecurityDrama\Ingest\FeedIngester;
use SecurityDrama\Scoring\RelevanceScorer;
use SecurityDrama\Selection\ContentSelector;
use SecurityDrama\Script\ScriptGenerator;
use SecurityDrama\Video\VideoOrchestrator;
use SecurityDrama\Publish\SocialPublisher;

final class Orchestrator
{
    private const MODULE = 'pipeline';

    public function run(): void
    {
        Logger::info(self::MODULE, 'Pipeline cycle started');

        $this->retrySweep();

        // Step 1: Ingest new feed items
        try {
            Logger::info(self::MODULE, 'Step 1: Feed ingestion');
            $ingester = new FeedIngester();
            $result = $ingester->run();
            Logger::info(self::MODULE, 'Feed ingestion complete', $result);
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Feed ingestion failed: ' . $e->getMessage());
        }

        // Step 2: Score unprocessed items
        try {
            Logger::info(self::MODULE, 'Step 2: Relevance scoring');
            $scorer = new RelevanceScorer();
            $scored = $scorer->run();
            Logger::info(self::MODULE, "Scored {$scored} items");
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Relevance scoring failed: ' . $e->getMessage());
        }

        // Step 3: Select items for content queue
        try {
            Logger::info(self::MODULE, 'Step 3: Content selection');
            $selector = new ContentSelector();
            $selected = $selector->run();
            Logger::info(self::MODULE, "Selected {$selected} items for queue");
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Content selection failed: ' . $e->getMessage());
        }

        // Step 4: Generate scripts for pending items
        try {
            Logger::info(self::MODULE, 'Step 4: Script generation');
            $scriptGen = new ScriptGenerator();
            $generated = $scriptGen->run();
            Logger::info(self::MODULE, "Generated {$generated} scripts");
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Script generation failed: ' . $e->getMessage());
        }

        // Step 5: Submit video generation jobs
        try {
            Logger::info(self::MODULE, 'Step 5: Video job submission');
            $videoOrch = new VideoOrchestrator();
            $submitted = $videoOrch->submitPending();
            Logger::info(self::MODULE, "Submitted {$submitted} video jobs");
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Video job submission failed: ' . $e->getMessage());
        }

        // Step 6: Poll video generation status
        try {
            Logger::info(self::MODULE, 'Step 6: Video status polling');
            $videoOrch = $videoOrch ?? new VideoOrchestrator();
            $polled = $videoOrch->pollInProgress();
            Logger::info(self::MODULE, "Polled {$polled} video jobs");
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Video status polling failed: ' . $e->getMessage());
        }

        // Step 7: Publish completed videos
        try {
            Logger::info(self::MODULE, 'Step 7: Social publishing');
            $publisher = new SocialPublisher();
            $publisher->run();
            Logger::info(self::MODULE, 'Social publishing complete');
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Social publishing failed: ' . $e->getMessage());
        }

        Logger::info(self::MODULE, 'Pipeline cycle complete');
    }

    /**
     * Reset failed items with retry_count < max_retry_count back to their previous pending state.
     */
    private function retrySweep(): void
    {
        $config = Config::getInstance();
        $maxRetries = (int) $config->get('max_retry_count', '3');
        $db = Database::getInstance();

        try {
            // Reset failed content_queue items back to appropriate pending state
            // Items with a script but no video go to pending_video
            $db->execute(
                'UPDATE content_queue
                 SET status = CASE
                     WHEN script_id IS NOT NULL THEN \'pending_video\'
                     ELSE \'pending_script\'
                 END,
                 failure_reason = NULL,
                 updated_at = NOW()
                 WHERE status = \'failed\'
                 AND retry_count < ?',
                [$maxRetries]
            );

            $affected = $db->execute(
                'SELECT ROW_COUNT() AS cnt'
            )->fetch();

            $count = (int) ($affected['cnt'] ?? 0);
            if ($count > 0) {
                Logger::info(self::MODULE, "Retry sweep: reset {$count} failed items for retry");
            }
        } catch (\Throwable $e) {
            Logger::error(self::MODULE, 'Retry sweep failed: ' . $e->getMessage());
        }
    }
}
