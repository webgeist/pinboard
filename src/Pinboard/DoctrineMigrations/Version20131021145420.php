<?php

namespace Pinboard\DoctrineMigrations;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

class Version20131021145420 extends AbstractMigration{

   public function up(Schema $schema)
   {

      $this->addSql("
         CREATE TABLE IF NOT EXISTS `ipm_script_details` (
           `server_name` varchar(64) DEFAULT NULL,
           `hostname` varchar(32) DEFAULT NULL,
           `script_name` varchar(128) DEFAULT NULL,
           `cpu_peak_usage` float DEFAULT NULL,
           `mem_peak_usage` float DEFAULT NULL,
           `req_time` float DEFAULT NULL,
           `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
      ");

   }

   public function down(Schema $schema)
   {

      $this->addSql("DROP TABLE `ipm_script_details`;");
   }
}