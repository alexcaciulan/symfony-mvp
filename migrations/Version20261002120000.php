<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The reference-rate table started on 2022-08-08, so any claim that fell due
 * earlier had no starting rate and its statutory interest came out
 * undetermined. This adds the BNR reference rates in force since OG 13/2011
 * (2011-09-01), as published in the BNR circulars. Rows already present are
 * left untouched.
 */
final class Version20261002120000 extends AbstractMigration
{
    private const RATES = [
        ['2011-09-01', '6.25'],
        ['2011-11-03', '6.00'],
        ['2012-01-06', '5.75'],
        ['2012-02-03', '5.50'],
        ['2012-03-30', '5.25'],
        ['2013-07-02', '5.00'],
        ['2013-08-06', '4.50'],
        ['2013-10-01', '4.25'],
        ['2013-11-06', '4.00'],
        ['2014-01-09', '3.75'],
        ['2014-02-05', '3.50'],
        ['2014-08-05', '3.25'],
        ['2014-10-01', '3.00'],
        ['2014-11-05', '2.75'],
        ['2015-01-08', '2.50'],
        ['2015-02-05', '2.25'],
        ['2015-04-01', '2.00'],
        ['2015-05-07', '1.75'],
        ['2018-01-09', '2.00'],
        ['2018-02-08', '2.25'],
        ['2018-05-08', '2.50'],
        ['2020-03-23', '2.00'],
        ['2020-06-02', '1.75'],
        ['2020-08-06', '1.50'],
        ['2021-01-18', '1.25'],
        ['2021-10-06', '1.50'],
        ['2021-11-10', '1.75'],
        ['2022-01-11', '2.00'],
        ['2022-02-10', '2.50'],
        ['2022-04-06', '3.00'],
        ['2022-05-11', '3.75'],
        ['2022-07-07', '4.75'],
    ];

    public function getDescription(): string
    {
        return 'Backfill BNR reference rates from 2011-09-01 to 2022-08-07';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RATES as [$validFrom, $rate]) {
            $this->addSql(
                'INSERT INTO interest_rate_config (valid_from, reference_rate, created_at, updated_at)
                 SELECT :validFrom, :rate, NOW(), NOW() FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM interest_rate_config WHERE valid_from = :validFrom)',
                ['validFrom' => $validFrom, 'rate' => $rate],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RATES as [$validFrom]) {
            $this->addSql('DELETE FROM interest_rate_config WHERE valid_from = :validFrom', ['validFrom' => $validFrom]);
        }
    }
}
