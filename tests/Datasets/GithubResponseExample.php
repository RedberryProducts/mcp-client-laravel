<?php

dataset('tools', [
    [
        'name' => 'add_issue_comment',
        'description' => 'Add a comment to a specific issue in a GitHub repository.',
        'annotations' => [
            'title' => 'Add comment to issue',
            'readOnlyHint' => false,
        ],
        'inputSchema' => [
            'type' => 'object',
            'required' => ['owner', 'repo', 'issue_number', 'body'],
            'properties' => [
                'body' => [
                    'description' => 'Comment content',
                    'type' => 'string',
                ],
                'issue_number' => [
                    'description' => 'Issue number to comment on',
                    'type' => 'number',
                ],
                'owner' => [
                    'description' => 'Repository owner',
                    'type' => 'string',
                ],
                'repo' => [
                    'description' => 'Repository name',
                    'type' => 'string',
                ],
            ],
        ],
    ],
]);
