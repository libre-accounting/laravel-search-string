<?php

namespace Lorisleiva\LaravelSearchString\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\LaravelSearchString\Tests\Stubs\Product;

/**
 * Runs generated queries against a real (SQLite in-memory) database, so the
 * where-clause grouping is checked by the rows that come back rather than by
 * the shape of the builder.
 */
class SearchQueryExecutionTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'search_string_sqlite');
        $app['config']->set('database.connections.search_string_sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('price')->default(0);
            $table->boolean('paid')->default(false);
            $table->boolean('boolean_variable')->default(false);
            $table->timestamps();
        });

        $rows = [
            1 => ['Maintenance service', 'Monthly upkeep', 50, '2020-03-10 10:00:00'],
            2 => ['Consulting', 'Includes maintenance visits', 5, '2020-11-02 10:00:00'],
            3 => ['Hosting', 'Shared server', 20, '2019-06-15 10:00:00'],
            4 => ['Domain', null, 15, '2021-01-20 10:00:00'],
            5 => ['Support plan', 'Phone support', 80, '2021-07-01 10:00:00'],
        ];

        foreach ($rows as $id => [$name, $description, $price, $createdAt]) {
            Product::query()->insert([
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    protected function ids(string $search): array
    {
        return Product::usingSearchString($search)->orderBy('id')->pluck('id')->all();
    }

    /** @test */
    public function free_text_search_matches_any_searchable_column()
    {
        $this->assertSame([1, 2], $this->ids('maint'));
        $this->assertSame([1, 2], $this->ids('"maint"'));
        $this->assertSame([3], $this->ids('server'));
    }

    /** @test */
    public function negated_free_text_search_excludes_matches_in_every_searchable_column()
    {
        // Row 4 has a NULL description, which `not like` never matches in SQL.
        $this->assertSame([3, 5], $this->ids('not maint'));
    }

    /** @test */
    public function free_text_search_stays_grouped_when_combined_with_other_clauses()
    {
        // Without the nested group, row 2 (cheap, matches on description) leaks in.
        $this->assertSame([1], $this->ids('price>10 maint'));
        $this->assertSame([1], $this->ids('maint price>10'));
        $this->assertSame([3, 5], $this->ids('price>10 not maint'));
        $this->assertSame([1, 2, 5], $this->ids('price>60 or maint'));
        $this->assertSame([2, 3, 5], $this->ids('price<10 or not maint'));
        $this->assertSame([2], $this->ids('maint not service'));
    }

    /** @test */
    public function date_ranges_include_and_exclude_the_right_rows()
    {
        $this->assertSame([1, 2], $this->ids('created_at = 2020'));
        $this->assertSame([3, 4, 5], $this->ids('not created_at = 2020'));
        $this->assertSame([2], $this->ids('created_at = 2020-11'));
        $this->assertSame([1, 3, 4, 5], $this->ids('not created_at = 2020-11'));
    }

    /** @test */
    public function date_ranges_stay_grouped_inside_or_expressions()
    {
        $this->assertSame([1, 2, 4], $this->ids('name:Domain or created_at = 2020'));
        $this->assertSame([1, 3, 4, 5], $this->ids('name:"Maintenance service" or not created_at = 2020'));
        $this->assertSame([2], $this->ids('created_at = 2020 maint price<10'));
    }
}
