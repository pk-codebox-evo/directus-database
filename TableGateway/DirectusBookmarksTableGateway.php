<?php

namespace Directus\Database\TableGateway;

use Directus\Permissions\Acl;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Insert;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Update;

class DirectusBookmarksTableGateway extends BaseTableGateway
{
    public static $_tableName = 'directus_bookmarks';

    public function __construct(AdapterInterface $adapter, Acl $acl)
    {
        parent::__construct(self::$_tableName, $adapter, $acl);
    }

    public static $defaultBookmarksValues = [
        'Activity' => [
            'title' => 'Activity',
            'url' => 'activity',
            'icon_class' => 'icon-bell',
            'section' => 'other'
        ],
        'Files' => [
            'title' => 'Files',
            'url' => 'files',
            'icon_class' => 'icon-attach',
            'section' => 'other'
        ],
        'Messages' => [
            'title' => 'Messages',
            'url' => 'messages',
            'icon_class' => 'icon-chat',
            'section' => 'other'
        ],
        'Users' => [
            'title' => 'Users',
            'url' => 'users',
            'icon_class' => 'icon-users',
            'section' => 'other'
        ]
    ];

    public function createDefaultBookmark($title, $bookmark)
    {
        // Global default values
        if (isset(self::$defaultBookmarksValues[$title])) {
            foreach (self::$defaultBookmarksValues[$title] as $field => $defaultValue) {
                if (!isset($bookmark[$field]) || ('0' !== $bookmark[$field] && empty($bookmark[$field]))) {
                    if (!isset($bookmark[$field])) {
                        $bookmark[$field] = $defaultValue;
                    }
                }
            }
        }
        return $bookmark;
    }

    public function updateBookmark($payload)
    {
        $update = new Update($this->table);
        $update->set($payload);
        $this->updateWith($update);
    }

    public function insertBookmark($payload)
    {
        $insert = new Insert($this->table);
        $insert->values($payload);
        $this->insertWith($insert);
        return $this->lastInsertValue;
    }

    public function fetchByUserAndId($user_id, $id)
    {
        $select = new Select($this->table);
        $select->limit(1);
        $select
            ->where
            ->equalTo('id', $id)
            ->equalTo('user', $user_id);

        $bookmarks = $this
            ->selectWith($select)
            ->current();

        if ($bookmarks) {
            $bookmarks = $bookmarks->toArray();
        }

        return $bookmarks ? $this->parseRecord($bookmarks) : [];
    }

    public function fetchAllByUser($user_id)
    {
        $select = new Select($this->table);
        $select
            ->where
            ->equalTo('user', $user_id);

        $bookmarks = $this->selectWith($select)->toArray();

        $defaultBookmarks = array_keys(self::$defaultBookmarksValues);

        foreach ($bookmarks as $index => $row) {
            $title = $row['title'];

            if (($key = array_search($title, $defaultBookmarks)) !== false) unset($defaultBookmarks[$key]);

            if (!isset($row['user'])) {
                $row = null;
            }

            // force: no activity nav item
            if ($row['url'] == 'activity') {
                unset($bookmarks[$index]);
                continue;
            }

            $bookmarks[$title] = $row;
        }

        foreach ($defaultBookmarks as $defaultBookmark) {
            $data = [
                'user' => $user_id
            ];

            $row = $this->createDefaultBookmark($defaultBookmark, $data);
            $id = $this->insertBookmark($row);

            $bookmarks[$defaultBookmark] = $row;
        }

        return $bookmarks;
    }
}
