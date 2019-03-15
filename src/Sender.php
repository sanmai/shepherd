<?php

namespace Psalm\Spirit;

class Sender
{
    public static function send(array $github_data, array $psalm_data) : void
    {
        $config_path = __DIR__ . '/../config.json';

        if (!file_exists($config_path)) {
            throw new \UnexpectedValueException('Missing config');
        }

        /**
         * @var array{reviewer: array{user: string, password: string, token: string}}
         */
        $config = json_decode(file_get_contents($config_path), true);

        $host = $config['host'] ?? 'https://github.com';

        $client = new \Github\Client(null, null, $config['host'] ?? null);
        $client->authenticate($config['reviewer']['token'], null, \Github\Client::AUTH_HTTP_TOKEN);

        $repository = $github_data['repository']['name'];
        $repository_owner = $github_data['repository']['owner']['login'];
        $pull_request_number = $github_data['pull_request']['number'];

        $head_sha = $github_data['pull_request']['head']['sha'];
        $base_sha = $github_data['pull_request']['base']['sha'];

        $pr_review_path = dirname(__DIR__) . '/database/pr_reviews/' . parse_url($github_data['pull_request']['html_url'], PHP_URL_PATH);
        $pr_comment_path = dirname(__DIR__) . '/database/pr_comments/' . parse_url($github_data['pull_request']['html_url'], PHP_URL_PATH);

        $review = null;

        if (file_exists($pr_review_path)) {
            $review = json_decode(file_get_contents($pr_review_path), true);

            $comments = $client
                ->api('pull_request')
                ->reviews()
                ->comments(
                    $repository_owner,
                    $repository,
                    $pull_request_number,
                    $review['id']
                );

            if (is_array($comments)) {
                foreach ($comments as $comment) {
                    $client
                        ->api('pull_request')
                        ->comments()
                        ->remove(
                            $repository_owner,
                            $repository,
                            $comment['id']
                        );
                }
            }
        }

        if (file_exists($pr_comment_path)) {
            $comment = json_decode(file_get_contents($pr_comment_path), true);

            $client
                ->api('issue')
                ->comments()
                ->remove(
                    $repository_owner,
                    $repository,
                    $comment['id']
                );
        }

        $diff_string = $client
            ->api('pull_request')
            ->configure('diff', 'v3')
            ->show(
                $repository_owner,
                $repository,
                $pull_request_number
            );

        $diff_parser = new \SebastianBergmann\Diff\Parser();
        $diffs = $diff_parser->parse($diff_string);

        var_dump($diff_url . $diff_string);

        /** @var array<int, array{severity: string, line_from: int, line_to: int, type: string, message: string,
         *      file_name: string, file_path: string, snippet: string, from: int, to: int,
         *      snippet_from: int, snippet_to: int, column_from: int, column_to: int, selected_text: string}>
         */
        $issues = $psalm_data['issues'];

        $file_comments = [];

        $missed_errors = [];

        foreach ($issues as $issue) {
            if ($issue['severity'] !== 'error') {
                continue;
            }

            $file_name = $issue['file_name'];

            foreach ($diffs as $diff) {
                var_dump($diff->getTo(), $file_name);

                if ($diff->getTo() === 'b/' . $file_name) {
                    $diff_file_offset = 0;

                    foreach ($diff->getChunks() as $chunk) {
                        $chunk_end = $chunk->getEnd();
                        $chunk_end_range = $chunk->getEndRange();

                        if ($issue['line_from'] >= $chunk_end
                            && $issue['line_from'] < $chunk_end + $chunk_end_range
                        ) {
                            $line_offset = 0;
                            foreach ($chunk->getLines() as $i => $chunk_line) {
                                $diff_file_offset++;

                                if ($chunk_line->getType() !== \SebastianBergmann\Diff\Line::REMOVED) {
                                    $line_offset++;
                                }

                                if ($issue['line_from'] === $line_offset + $chunk_end - 1) {
                                    $snippet = $issue['snippet'];
                                    $selected_text = $issue['selected_text'];

                                    $selection_start = $issue['from'] - $issue['snippet_from'];
                                    $selection_length = $issue['to'] - $issue['from'];

                                    $before_snippet = substr($snippet, 0, $selection_start);

                                    $before_lines = explode("\n", $before_snippet);

                                    $last_before_line_length = strlen(array_pop($before_lines));

                                    $first_selected_line = explode("\n", $selected_text)[0];

                                    $issue_string = $before_snippet . $first_selected_line
                                        . "\n" . str_repeat(' ', $last_before_line_length) . '^';

                                    $file_comments[] = [
                                        'path' => $file_name,
                                        'position' => $diff_file_offset,
                                        'body' => $issue['message'] . "\n```\n"
                                            . $issue_string . "\n```",
                                    ];
                                    continue 4;
                                }
                            }
                        } else {
                            $diff_file_offset += count($chunk->getLines());
                        }
                    }
                }
            }

            $missed_errors[] = $file_name . ':' . $issue['line_from'] . ':' . $issue['column_from'] . ' - ' . $issue['message'];
        }

        if ($missed_errors) {
            $comment_text = "\n\n```\n" . implode("\n", $missed_errors) . "\n```";

            if ($file_comments) {
                $message_body = 'Psalm also found errors in other files' . $comment_text;
            } else {
                $message_body = 'Psalm found errors in other files' . $comment_text;
            }
        } elseif ($file_comments) {
            $message_body = 'Psalm found some errors';
        } elseif ($review) {
            $message_body = 'Psalm didn’t find any errors!';
        } else {
            return;
        }

        if ($file_comments) {
            $review = $client
                ->api('pull_request')
                ->reviews()
                ->create(
                    $repository_owner,
                    $repository,
                    $pull_request_number,
                    [
                        'commit_id' => $head_sha,
                        'body' => '',
                        'comments' => $file_comments,
                        'event' => 'REQUEST_CHANGES',
                    ]
                );

            $pr_review_path_dir = dirname($pr_review_path);

            mkdir($pr_review_path_dir, 0777, true);

            file_put_contents($pr_review_path, json_encode($review));
        }

        $comment = $client
            ->api('issue')
            ->comments()
            ->create(
                $repository_owner,
                $repository,
                $pull_request_number,
                [
                    'body' => $message_body,
                ]
            );

        $pr_comment_path_dir = dirname($pr_comment_path);

        mkdir($pr_comment_path_dir, 0777, true);

        file_put_contents($pr_comment_path, json_encode($comment));
    }
}