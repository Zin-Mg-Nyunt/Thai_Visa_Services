# Phase 4 performance benchmark (Job Search queries)

> **Provenance.** Output of `tests/Concurrency/Mysql/JobSearchQueryBenchmarkMysqlTest.php`, run on 2026-11-19 against the dedicated `Thai_Visa_Services_Testing` database with a seeded synthetic data set (no real data). It backs the review findings P1–P5 in `JOB_SEARCH_TECHNICAL_DECISIONS.md` §17.22.
>
> **Sanitised copy.** The only change from the original run output: the operating-system build suffix of the MySQL server version (line 1 of the original) was removed, leaving `MySQL 8.4.13`. Every measurement, row count and EXPLAIN plan below is unchanged. Synthetic ids (for example `job_order_id=3162`) refer to rows that were deleted after the run.
>
> **Reading the numbers.** Medians from one development machine. They compare the code before and after the review; they do not predict a production server.

## Run output (MySQL 8.4.13)

Synthetic data set (seed 2026), built in 42.6 s. Rows in the testing database during the run: {"clients":40000,"client_services":40000,"job_orders":2000,"job_order_workers":39466,"payments":59809}.
Times are medians of 15 runs after 2 warm-ups. "rows read" is the sum of the session Handler_read_* counters for one run.

### P1 Job Order Money

Same answers as the original queries on the 20 sampled orders and the client-page lookup: mismatches = 0.

| query                                          | before: queries / ms / rows read | after: queries / ms / rows read |
| ---------------------------------------------- | -------------------------------- | ------------------------------- |
| forOrder (order page, order with 200+ workers) | 2 / 844.62 / 245392              | 1 / 16.4 / 2906                 |
| ordersOf (client page, 3 records)              | 1 / 177.96 / 72326               | 1 / 2.87 / 24                   |
| totals (not used by any page; unchanged)       | 2 / 1414.31 / 295483             | 2 / 1283.34 / 295483            |

#### EXPLAIN forOrder, before (first of its two queries)

| id  | select_type | table      | type   | key                              | rows  | filtered | Extra                        |
| --- | ----------- | ---------- | ------ | -------------------------------- | ----- | -------- | ---------------------------- |
| 1   | PRIMARY     | <derived2> | ALL    |                                  | 2051  | 100      | Using where                  |
| 1   | PRIMARY     | a          | eq_ref | PRIMARY                          | 1     | 5        | Using where                  |
| 1   | PRIMARY     | cs         | eq_ref | PRIMARY                          | 1     | 100      |                              |
| 1   | PRIMARY     | <derived3> | ref    | <auto_key0>                      | 10    | 100      |                              |
| 3   | DERIVED     | payments   | index  | payments_client_service_id_index | 58459 | 100      |                              |
| 2   | DERIVED     | w          | ALL    |                                  | 38565 | 28       | Using where; Using temporary |
| 2   | DERIVED     | c          | eq_ref | PRIMARY                          | 1     | 19       | Using where                  |

#### EXPLAIN forOrder, after

| id  | select_type  | table             | type   | key                                         | rows | filtered | Extra                            |
| --- | ------------ | ----------------- | ------ | ------------------------------------------- | ---- | -------- | -------------------------------- |
| 1   | PRIMARY      | <derived2>        | ALL    |                                             | 23   | 100      | Using where                      |
| 1   | PRIMARY      | a                 | eq_ref | PRIMARY                                     | 1    | 5        | Using where                      |
| 1   | PRIMARY      | cs                | eq_ref | PRIMARY                                     | 1    | 100      |                                  |
| 1   | PRIMARY      | clients           | eq_ref | PRIMARY                                     | 1    | 100      |                                  |
| 1   | PRIMARY      | <derived4>        | ref    | <auto_key0>                                 | 2    | 100      |                                  |
| 4   | DERIVED      | <subquery5>       | ALL    |                                             |      | 100      | Using temporary                  |
| 4   | DERIVED      | payments          | ref    | payments_client_service_id_index            | 1    | 100      |                                  |
| 5   | MATERIALIZED | job_order_workers | ref    | job_order_workers_job_order_id_foreign      | 213  | 100      |                                  |
| 2   | DERIVED      | job_order_workers | ref    | job_order_workers_job_order_id_foreign      | 213  | 100      | Using temporary; Start temporary |
| 2   | DERIVED      | c                 | eq_ref | PRIMARY                                     | 1    | 19       | Using where                      |
| 2   | DERIVED      | w                 | ref    | job_order_workers_client_service_id_foreign | 2    | 28       | Using where; End temporary       |

#### EXPLAIN ANALYZE forOrder, before (first query)

```
-> Limit: 1 row(s)  (cost=619333 rows=1) (actual time=728..728 rows=1 loops=1)
    -> Aggregate: sum(((coalesce(paid.satang,0) - round((cs.refund_amount * 100),0)) - round((cs.cancel_refund_amount * 100),0)))  (cost=619333 rows=1) (actual time=728..728 rows=1 loops=1)
        -> Nested loop left join  (cost=310288 rows=3.09e+6) (actual time=675..728 rows=2 loops=1)
            -> Nested loop inner join  (cost=987 rows=103) (actual time=220..273 rows=2 loops=1)
                -> Nested loop inner join  (cost=951 rows=103) (actual time=220..273 rows=2 loops=1)
                    -> Filter: (q.application_id is not null)  (cost=0.114..233 rows=2051) (actual time=209..214 rows=10244 loops=1)
                        -> Table scan on q  (cost=2.5..2.5 rows=0) (actual time=209..212 rows=10244 loops=1)
                            -> Materialize  (cost=0..0 rows=0) (actual time=209..209 rows=10244 loops=1)
                                -> Table scan on <temporary>  (actual time=205..207 rows=10244 loops=1)
                                    -> Aggregate using temporary table  (actual time=205..205 rows=10244 loops=1)
                                        -> Nested loop inner join  (cost=4915 rows=2052) (actual time=0.354..185 rows=10744 loops=1)
                                            -> Filter: ((w.case_status in ('applied','interview')) or (w.case_status = 'hired'))  (cost=1136 rows=10798) (actual time=0.304..74.7 rows=11840 loops=1)
                                                -> Table scan on w  (cost=1136 rows=38565) (actual time=0.294..46.9 rows=39466 loops=1)
                                            -> Filter: (((c.`status` = 'in_progress') and (w.case_status in ('applied','interview'))) or ((c.`status` = 'complete') and (w.case_status = 'hired')))  (cost=0.25 rows=0.19) (actual time=0.00868..0.00889 rows=0.907 loops=11840)
                                                -> Single-row index lookup on c using PRIMARY (id=w.client_service_id)  (cost=0.25 rows=1) (actual time=0.00698..0.00705 rows=1 loops=11840)
                    -> Filter: (a.job_order_id = 3162)  (cost=0.25 rows=0.05) (actual time=0.00554..0.00554 rows=195e-6 loops=10244)
                        -> Single-row index lookup on a using PRIMARY (id=q.application_id)  (cost=0.25 rows=1) (actual time=0.00505..0.00511 rows=1 loops=10244)
                -> Single-row index lookup on cs using PRIMARY (id=a.client_service_id)  (cost=0.251 rows=1) (actual time=0.0216..0.0217 rows=1 loops=2)
            -> Index lookup on paid using <auto_key0> (client_service_id=a.client_service_id)  (cost=14778..14780 rows=10) (actual time=227..227 rows=0.5 loops=2)
                -> Materialize  (cost=14778..14778 rows=30136) (actual time=455..455 rows=29849 loops=1)
                    -> Group aggregate: sum(round((payments.amount * 100),0))  (cost=11764 rows=30136) (actual time=31.4..360 rows=29849 loops=1)
                        -> Index scan on payments using payments_client_service_id_index  (cost=5918 rows=58459) (actual time=31.4..310 rows=59809 loops=1)

```

#### EXPLAIN ANALYZE forOrder, after

```
-> Limit: 1 row(s)  (cost=14.5 rows=1) (actual time=14.6..14.6 rows=1 loops=1)
    -> Aggregate: sum(((coalesce(paid.satang,0) - round((cs.refund_amount * 100),0)) - round((cs.cancel_refund_amount * 100),0))), sum((case when (clients.deleted_at is not null) then 1 else 0 end))  (cost=14.5 rows=1) (actual time=14.6..14.6 rows=1 loops=1)
        -> Nested loop left join  (cost=14.5 rows=0) (actual time=13.4..14.6 rows=2 loops=1)
            -> Nested loop inner join  (cost=13.9 rows=1.15) (actual time=6.68..7.81 rows=2 loops=1)
                -> Nested loop inner join  (cost=13.5 rows=1.15) (actual time=6.66..7.78 rows=2 loops=1)
                    -> Nested loop inner join  (cost=13.1 rows=1.15) (actual time=6.65..7.75 rows=2 loops=1)
                        -> Filter: (q.application_id is not null)  (cost=0.221..5.09 rows=23) (actual time=6.64..6.69 rows=106 loops=1)
                            -> Table scan on q  (cost=2.5..2.5 rows=0) (actual time=6.63..6.67 rows=106 loops=1)
                                -> Materialize  (cost=0..0 rows=0) (actual time=6.63..6.63 rows=106 loops=1)
                                    -> Table scan on <temporary>  (actual time=6.54..6.57 rows=106 loops=1)
                                        -> Aggregate using temporary table  (actual time=6.53..6.53 rows=106 loops=1)
                                            -> Remove duplicate (c, w) rows using temporary table (weedout)  (cost=178 rows=23.2) (actual time=0.649..6.38 rows=113 loops=1)
                                                -> Nested loop inner join  (cost=178 rows=23.2) (actual time=0.643..6.25 rows=113 loops=1)
                                                    -> Nested loop inner join  (cost=149 rows=40.5) (actual time=0.625..2.41 rows=151 loops=1)
                                                        -> Index lookup on job_order_workers using job_order_workers_job_order_id_foreign (job_order_id=3162)  (cost=74.5 rows=213) (actual time=0.58..1.02 rows=213 loops=1)
                                                        -> Filter: ((c.`status` = 'in_progress') or (c.`status` = 'complete'))  (cost=0.25 rows=0.19) (actual time=0.00608..0.00623 rows=0.709 loops=213)
                                                            -> Single-row index lookup on c using PRIMARY (id=job_order_workers.client_service_id)  (cost=0.25 rows=1) (actual time=0.00521..0.00527 rows=1 loops=213)
                                                    -> Filter: (((c.`status` = 'in_progress') and (w.case_status in ('applied','interview'))) or ((c.`status` = 'complete') and (w.case_status = 'hired')))  (cost=0.514 rows=0.574) (actual time=0.022..0.0249 rows=0.748 loops=151)
                                                        -> Index lookup on w using job_order_workers_client_service_id_foreign (client_service_id=job_order_workers.client_service_id)  (cost=0.514 rows=2.05) (actual time=0.0188..0.0214 rows=3 loops=151)
                        -> Filter: (a.job_order_id = 3162)  (cost=0.25 rows=0.05) (actual time=0.00966..0.00966 rows=0.0189 loops=106)
                            -> Single-row index lookup on a using PRIMARY (id=q.application_id)  (cost=0.25 rows=1) (actual time=0.00878..0.00884 rows=1 loops=106)
                    -> Single-row index lookup on cs using PRIMARY (id=a.client_service_id)  (cost=0.337 rows=1) (actual time=0.0128..0.0129 rows=1 loops=2)
                -> Single-row index lookup on clients using PRIMARY (id=a.client_id)  (cost=0.337 rows=1) (actual time=0.0127..0.0129 rows=1 loops=2)
            -> Index lookup on paid using <auto_key0> (client_service_id=a.client_service_id)  (cost=0.337..0.674 rows=2) (actual time=3.37..3.37 rows=0.5 loops=2)
                -> Materialize  (cost=0..0 rows=0) (actual time=6.73..6.73 rows=157 loops=1)
                    -> Table scan on <temporary>  (actual time=6.35..6.39 rows=157 loops=1)
                        -> Aggregate using temporary table  (actual time=6.35..6.35 rows=157 loops=1)
                            -> Nested loop inner join  (cost=246 rows=413) (actual time=1.51..5.44 rows=318 loops=1)
                                -> Table scan on <subquery5>  (cost=95.9..101 rows=213) (actual time=1.47..1.55 rows=213 loops=1)
                                    -> Materialize with deduplication  (cost=95.8..95.8 rows=213) (actual time=1.47..1.47 rows=213 loops=1)
                                        -> Index lookup on job_order_workers using job_order_workers_job_order_id_foreign (job_order_id=3162)  (cost=74.5 rows=213) (actual time=0.77..1.33 rows=213 loops=1)
                                -> Index lookup on payments using payments_client_service_id_index (client_service_id=`<subquery5>`.client_service_id)  (cost=103 rows=1.94) (actual time=0.0157..0.0174 rows=1.49 loops=213)

```

#### EXPLAIN ANALYZE ordersOf, before

```
-> Nested loop inner join  (cost=36.2 rows=0) (actual time=209..209 rows=1 loops=1)
    -> Index range scan on a using job_order_workers_client_service_id_foreign over (client_service_id = 42552) OR (client_service_id = 42553) OR (client_service_id = 42554), with index condition: (a.client_service_id in (42552,42553,42554))  (cost=6.16 rows=12) (actual time=0.0519..0.158 rows=12 loops=1)
    -> Covering index lookup on q using <auto_key0> (application_id=a.id)  (cost=0.258..2.58 rows=10) (actual time=17.4..17.4 rows=0.0833 loops=12)
        -> Materialize  (cost=0..0 rows=0) (actual time=209..209 rows=10244 loops=1)
            -> Table scan on <temporary>  (actual time=183..185 rows=10244 loops=1)
                -> Aggregate using temporary table  (actual time=183..183 rows=10244 loops=1)
                    -> Nested loop inner join  (cost=4915 rows=2052) (actual time=0.171..165 rows=10744 loops=1)
                        -> Filter: ((w.case_status in ('applied','interview')) or (w.case_status = 'hired'))  (cost=1136 rows=10798) (actual time=0.142..65.5 rows=11840 loops=1)
                            -> Table scan on w  (cost=1136 rows=38565) (actual time=0.137..41.8 rows=39466 loops=1)
                        -> Filter: (((c.`status` = 'in_progress') and (w.case_status in ('applied','interview'))) or ((c.`status` = 'complete') and (w.case_status = 'hired')))  (cost=0.25 rows=0.19) (actual time=0.00782..0.008 rows=0.907 loops=11840)
                            -> Single-row index lookup on c using PRIMARY (id=w.client_service_id)  (cost=0.25 rows=1) (actual time=0.00631..0.00637 rows=1 loops=11840)

```

#### EXPLAIN ANALYZE ordersOf, after

```
-> Nested loop inner join  (cost=3.43 rows=0.1) (actual time=0.185..0.186 rows=1 loops=1)
    -> Filter: (q.application_id is not null)  (cost=1.36..2.73 rows=2) (actual time=0.174..0.174 rows=1 loops=1)
        -> Table scan on q  (cost=2.5..2.5 rows=0) (actual time=0.173..0.173 rows=1 loops=1)
            -> Materialize  (cost=0..0 rows=0) (actual time=0.172..0.172 rows=1 loops=1)
                -> Table scan on <temporary>  (actual time=0.16..0.161 rows=1 loops=1)
                    -> Aggregate using temporary table  (actual time=0.158..0.158 rows=1 loops=1)
                        -> Nested loop inner join  (cost=1.53 rows=0.327) (actual time=0.141..0.146 rows=1 loops=1)
                            -> Filter: ((c.id in (42552,42553,42554)) and ((c.`status` = 'in_progress') or (c.`status` = 'complete')))  (cost=1.12 rows=0.57) (actual time=0.0313..0.0439 rows=3 loops=1)
                                -> Index range scan on c using PRIMARY over (id = 42552) OR (id = 42553) OR (id = 42554)  (cost=1.12 rows=3) (actual time=0.0266..0.0369 rows=3 loops=1)
                            -> Filter: (((c.`status` = 'in_progress') and (w.case_status in ('applied','interview'))) or ((c.`status` = 'complete') and (w.case_status = 'hired')))  (cost=0.613 rows=0.574) (actual time=0.0315..0.033 rows=0.333 loops=3)
                                -> Index lookup on w using job_order_workers_client_service_id_foreign (client_service_id=c.id)  (cost=0.613 rows=2.05) (actual time=0.026..0.0289 rows=4 loops=3)
    -> Filter: (a.client_service_id in (42552,42553,42554))  (cost=0.253 rows=0.05) (actual time=0.00961..0.00986 rows=1 loops=1)
        -> Single-row index lookup on a using PRIMARY (id=q.application_id)  (cost=0.253 rows=1) (actual time=0.00876..0.00884 rows=1 loops=1)

```

### P2 Order page worker loading (order with 213 applications)

|                                                       | queries | ms     | rows read | PHP memory held by the result |
| ----------------------------------------------------- | ------- | ------ | --------- | ----------------------------- |
| before (two loads, all payment columns, person twice) | 10      | 177.2  | 1639      | 2389 KiB                      |
| after (one load, split in PHP, amount column only)    | 4       | 141.13 | 1244      | 1833 KiB                      |

### P3 Composite indexes on job_order_workers

#### with the existing indexes only (foreign keys and client_id)

| query                                                | ms    | rows read |
| ---------------------------------------------------- | ----- | --------- |
| order list counts (withWorkerCounts, 10 orders)      | 22.72 | 2721      |
| close preview (open applications of one order)       | 2.95  | 214       |
| Add Worker duplicate check (order + person + active) | 4.55  | 4         |
| FindEngagement (applications of one record)          | 3.95  | 4         |
| forOrder (after P1)                                  | 20.78 | 2906      |

EXPLAIN of the order list counts query:

| id  | select_type        | table             | type   | key                                    | rows | filtered | Extra          |
| --- | ------------------ | ----------------- | ------ | -------------------------------------- | ---- | -------- | -------------- |
| 1   | PRIMARY            | job_orders        | ALL    |                                        | 2000 | 100      | Using filesort |
| 7   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 19   | 10       | Using where    |
| 7   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10       | Using where    |
| 5   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 19   | 10       | Using where    |
| 5   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10       | Using where    |
| 2   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 19   | 20       | Using where    |
| 2   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10       | Using where    |
| 2   | DEPENDENT SUBQUERY | client_services   | eq_ref | PRIMARY                                | 1    | 90       | Using where    |

Index set while measuring: {"bench_tmp_order_status":"job_order_id,case_status","bench_tmp_record_status":"client_service_id,case_status","job_order_workers_client_id_index":"client_id","job_order_workers_client_service_id_foreign":"client_service_id","job_order_workers_job_order_id_foreign":"job_order_id","PRIMARY":"id"} (MySQL drops a foreign key's own index once a composite index starting with that column exists).

#### with temporary (job_order_id, case_status) and (client_service_id, case_status) indexes

| query                                                | ms    | rows read |
| ---------------------------------------------------- | ----- | --------- |
| order list counts (withWorkerCounts, 10 orders)      | 27.37 | 2461      |
| close preview (open applications of one order)       | 2.93  | 3         |
| Add Worker duplicate check (order + person + active) | 3.11  | 4         |
| FindEngagement (applications of one record)          | 2.09  | 4         |
| forOrder (after P1)                                  | 19.68 | 2906      |

EXPLAIN of the order list counts query:

| id  | select_type        | table             | type   | key                                    | rows | filtered | Extra          |
| --- | ------------------ | ----------------- | ------ | -------------------------------------- | ---- | -------- | -------------- |
| 1   | PRIMARY            | job_orders        | ALL    |                                        | 2000 | 100      | Using filesort |
| 7   | DEPENDENT SUBQUERY | job_order_workers | ref    | bench_tmp_order_status                 | 4    | 100      |                |
| 7   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10       | Using where    |
| 5   | DEPENDENT SUBQUERY | job_order_workers | ref    | bench_tmp_order_status                 | 4    | 100      |                |
| 5   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10       | Using where    |
| 2   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 19   | 20       | Using where    |
| 2   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10       | Using where    |
| 2   | DEPENDENT SUBQUERY | client_services   | eq_ref | PRIMARY                                | 1    | 90       | Using where    |

### P4 Company search, LIKE '%term%' (2000 job orders)

| search                | ms (count + page) | rows read |
| --------------------- | ----------------- | --------- |
| common term "and"     | 30.71             | 4722      |
| rare term "Zzq"       | 10.53             | 2002      |
| no search (list only) | 19.04             | 4723      |

EXPLAIN of the page query with a search:

| id  | select_type        | table             | type   | key                                    | rows | filtered        | Extra                       |
| --- | ------------------ | ----------------- | ------ | -------------------------------------- | ---- | --------------- | --------------------------- |
| 1   | PRIMARY            | job_orders        | ALL    |                                        | 2000 | 11.110000610352 | Using where; Using filesort |
| 7   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 20   | 10              | Using where                 |
| 7   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10              | Using where                 |
| 5   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 20   | 10              | Using where                 |
| 5   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10              | Using where                 |
| 2   | DEPENDENT SUBQUERY | job_order_workers | ref    | job_order_workers_job_order_id_foreign | 20   | 20              | Using where                 |
| 2   | DEPENDENT SUBQUERY | clients           | eq_ref | PRIMARY                                | 1    | 10              | Using where                 |
| 2   | DEPENDENT SUBQUERY | client_services   | eq_ref | PRIMARY                                | 1    | 90              | Using where                 |

### P5 Landing statistics

| step                                                                          | ms     | rows read |
| ----------------------------------------------------------------------------- | ------ | --------- |
| compute (the two counts a cache miss runs)                                    | 52.75  | 80006     |
| refresh after commit, uncontended, database cache store (lock + counts + put) | 100.53 | 80008     |
