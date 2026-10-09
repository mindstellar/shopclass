<?php

// Written by tests/models/search-parity.php --write. Labels, totals and statement counts per case.

return array(
  'unfiltered' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 7,
  ),
  'unfiltered, no count' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => null,
    'q' => 5,
  ),
  'unfiltered, not extended' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 2,
  ),
  'count() before doSearch' =>
  array(
    'count' => 8,
    'q' => 6,
  ),
  'count after uncounted' =>
  array(
    'count' => 0,
    'q' => 5,
  ),
  'page 1 of 3' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
      2 => 'car3',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'limit offset 2 size 3' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'bike2',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'set_rpp 2' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'page past the end' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 8,
    'q' => 2,
  ),
  'category root (subcategories included)' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'car3',
    ),
    'count' => 4,
    'q' => 7,
  ),
  'subcategory' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'category by slug' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
    ),
    'count' => 2,
    'q' => 7,
  ),
  'category by path slug' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 7,
  ),
  'two categories' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
      2 => 'sofa',
      3 => 'tv',
    ),
    'count' => 4,
    'q' => 6,
  ),
  'unknown slug' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 7,
  ),
  'category id as string' =>
  array(
    'ids' =>
    array(
      0 => 'sofa',
      1 => 'tv',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'region id' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'region id as string' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'region name' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car3',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'region name wildcard' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'regions array' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'bike2',
      3 => 'car3',
      4 => 'sofa',
    ),
    'count' => 5,
    'q' => 6,
  ),
  'region leading zero' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'region name quote' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'city id' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'city name' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'cities array' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'bike2',
      3 => 'car3',
      4 => 'sofa',
    ),
    'count' => 5,
    'q' => 6,
  ),
  'city area id' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'city area name' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'city areas array' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'sport',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'country code' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car3',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'country code lower' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'sofa',
      5 => 'tv',
    ),
    'count' => 6,
    'q' => 6,
  ),
  'country name' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car3',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'countries array' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'empty region' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'zero city' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'region and city' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'country, region, category' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'price range' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'bike2',
      2 => 'car3',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'price min only' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'car3',
    ),
    'count' => 4,
    'q' => 6,
  ),
  'price max only' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'sofa',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'price zero range' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'price string' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'bike2',
      2 => 'car3',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'price float' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'priceMin()' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'sport',
      2 => 'car3',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'priceMax()' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'sofa',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'pattern word' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'sofa',
    ),
    'count' => 6,
    'q' => 7,
  ),
  'pattern two words' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern prefix' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern exclusion' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern phrase' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern short term' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'tv',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'pattern short terms' =>
  array(
    'ids' =>
    array(
      0 => 'tv',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern quote' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'pattern wildcard' =>
  array(
    'ids' =>
    array(
      0 => 'car3',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern underscore' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'pattern operators' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern no match' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'pattern only operator' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'pattern relevance' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'pattern short rel.' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'tv',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'pattern with es_ES locale' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern in user locale misses es_ES' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'pattern two locales' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'pattern, category, price, premium' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'pattern and location' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'user id' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'user id string' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'username' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car3',
    ),
    'count' => 2,
    'q' => 8,
  ),
  'unknown username' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 3,
  ),
  'user ids array' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'bike2',
      3 => 'car3',
      4 => 'tv',
    ),
    'count' => 5,
    'q' => 6,
  ),
  'usernames array' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'tv',
    ),
    'count' => 5,
    'q' => 9,
  ),
  'with picture' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'sport',
      2 => 'bike1',
      3 => 'car3',
    ),
    'count' => 4,
    'q' => 6,
  ),
  'only premium' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'sport',
      2 => 'bike2',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'picture and premium' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'contact email' =>
  array(
    'ids' =>
    array(
      0 => 'sofa',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'contact email quote' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'not from user' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'bike2',
      3 => 'car3',
      4 => 'sofa',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'item id' =>
  array(
    'ids' =>
    array(
      0 => 'car3',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'item id of a hidden listing' =>
  array(
    'ids' =>
    array(
      0 => 'hidden',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'include hidden' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
      8 => 'hidden',
      9 => 'expired',
    ),
    'count' => 11,
    'q' => 6,
  ),
  'include hidden then not' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'new Search(true)' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
      8 => 'hidden',
      9 => 'expired',
    ),
    'count' => 11,
    'q' => 6,
  ),
  'from primary keys' =>
  array(
    'ids' =>
    array(
      0 => 'car3',
      1 => 'bike1',
      2 => 'sofa',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'from primary keys unordered' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'car3',
      2 => 'sofa',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'from primary keys empty' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 8,
    'q' => 2,
  ),
  'order price asc' =>
  array(
    'ids' =>
    array(
      0 => 'sofa',
      1 => 'tv',
      2 => 'bike1',
      3 => 'bike2',
      4 => 'car1',
      5 => 'car3',
      6 => 'car2',
      7 => 'sport',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order price desc' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'car2',
      2 => 'car3',
      3 => 'car1',
      4 => 'bike2',
      5 => 'bike1',
      6 => 'tv',
      7 => 'sofa',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order date asc' =>
  array(
    'ids' =>
    array(
      0 => 'tv',
      1 => 'sofa',
      2 => 'car3',
      3 => 'bike2',
      4 => 'bike1',
      5 => 'sport',
      6 => 'car2',
      7 => 'car1',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order expiration' =>
  array(
    'ids' =>
    array(
      0 => 'tv',
      1 => 'sofa',
      2 => 'car3',
      3 => 'bike2',
      4 => 'bike1',
      5 => 'sport',
      6 => 'car2',
      7 => 'car1',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order lower-case dir' =>
  array(
    'ids' =>
    array(
      0 => 'sofa',
      1 => 'tv',
      2 => 'bike1',
      3 => 'bike2',
      4 => 'car1',
      5 => 'car3',
      6 => 'car2',
      7 => 'sport',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order bad direction' =>
  array(
    'ids' =>
    array(
      0 => 'sofa',
      1 => 'tv',
      2 => 'bike1',
      3 => 'bike2',
      4 => 'car1',
      5 => 'car3',
      6 => 'car2',
      7 => 'sport',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order random' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 8,
    'q' => 2,
  ),
  'order bad column' =>
  array(
    'ids' =>
    array(
      0 => 'tv',
      1 => 'sofa',
      2 => 'car3',
      3 => 'bike2',
      4 => 'bike1',
      5 => 'sport',
      6 => 'car2',
      7 => 'car1',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order relevance no pat' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 8,
    'q' => 2,
  ),
  'order qualified' =>
  array(
    'ids' =>
    array(
      0 => 'sofa',
      1 => 'tv',
      2 => 'bike1',
      3 => 'bike2',
      4 => 'car1',
      5 => 'car3',
      6 => 'car2',
      7 => 'sport',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order mod date' =>
  array(
    'ids' =>
    array(
      0 => 'tv',
      1 => 'sofa',
      2 => 'car3',
      3 => 'bike2',
      4 => 'bike1',
      5 => 'sport',
      6 => 'car2',
      7 => 'car1',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'order by t_user' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
      2 => 'car1',
      3 => 'car2',
      4 => 'car3',
      5 => 'sport',
      6 => 'tv',
    ),
    'count' => 7,
    'q' => 6,
  ),
  'orderBy several columns' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car2',
      2 => 'sport',
      3 => 'sofa',
      4 => 'tv',
      5 => 'bike1',
      6 => 'car1',
      7 => 'car3',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'addConditions string' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike2',
      4 => 'car3',
    ),
    'count' => 5,
    'q' => 6,
  ),
  'addConditions array, duplicate' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
    ),
    'count' => 6,
    'q' => 6,
  ),
  'addConditions with literal question mark' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'addCondition with params' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'addItemConditions' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'sport',
      2 => 'bike2',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'addTable and condition' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'addJoinTable and field' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'addJoinTable bad type' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'tv',
    ),
    'count' => 7,
    'q' => 6,
  ),
  'addField with comma' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'bike1',
      4 => 'bike2',
      5 => 'car3',
      6 => 'sofa',
      7 => 'tv',
    ),
    'count' => 8,
    'q' => 6,
  ),
  'addGroupBy and addHaving' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'sport',
      2 => 'bike1',
      3 => 'car3',
    ),
    'count' => 4,
    'q' => 6,
  ),
  'dao where, select, join' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
    ),
    'count' => 3,
    'q' => 7,
  ),
  'dao orderBy and key/value where' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car3',
      2 => 'bike2',
      3 => 'car2',
      4 => 'sport',
      5 => 'bike1',
      6 => 'tv',
    ),
    'count' => 7,
    'q' => 6,
  ),
  'broken plugin condition' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'sql_search_conditions filter' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'tv',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'sql_search_item_conditions filter' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'builder: full request' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 7,
  ),
  'builder: pattern, user, premium' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'builder: comma lists' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'count' => 3,
    'q' => 6,
  ),
  'builder: page 2' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 8,
    'q' => 2,
  ),
  'builder: meta TEXT' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta TEXTAREA' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta URL' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta DROPDOWN' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta RADIO' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta CHECKBOX' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta DATE' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta DATEINTERVAL' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'builder: meta NUMBER' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car3',
    ),
    'count' => 2,
    'q' => 9,
  ),
  'builder: meta outside its category' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
    ),
    'count' => 2,
    'q' => 8,
  ),
  'builder: meta wildcard' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
    ),
    'count' => 2,
    'q' => 9,
  ),
  'runner: request' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'car2',
      2 => 'car3',
      3 => 'car1',
    ),
    'count' => 4,
    'q' => 8,
  ),
  'runner: uncounted with shape' =>
  array(
    'ids' =>
    array(
      0 => 'tv',
      1 => 'bike1',
      2 => 'car1',
    ),
    'count' => null,
    'q' => 5,
  ),
  'premiums' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car2',
      2 => 'sport',
    ),
    'q' => 6,
  ),
  'premiums limit 1' =>
  array(
    'ids' =>
    array(
      0 => 1,
    ),
    'q' => 4,
  ),
  'premiums in category' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'sport',
    ),
    'q' => 5,
  ),
  'premiums in region' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
    ),
    'q' => 4,
  ),
  'premiums with pattern' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
    ),
    'q' => 4,
  ),
  'premiums with pattern and city' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'q' => 4,
  ),
  'latest 3' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
    ),
    'q' => 6,
  ),
  'latest by category' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sport',
      3 => 'car3',
    ),
    'q' => 6,
  ),
  'latest by country' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car3',
    ),
    'q' => 6,
  ),
  'latest by region' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'tv',
    ),
    'q' => 6,
  ),
  'latest by city' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
      2 => 'sofa',
    ),
    'q' => 6,
  ),
  'latest by user' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
    ),
    'q' => 6,
  ),
  'latest with photo' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'sport',
      2 => 'bike1',
      3 => 'car3',
    ),
    'q' => 6,
  ),
  'countAll' =>
  array(
    'count' => 11,
    'q' => 1,
  ),
  'listCityAreas' =>
  array(
    'ids' =>
    array(
      0 => 'Downtown=1',
      1 => 'Uptown=1',
    ),
    'q' => 1,
  ),
  'listCityAreas of a city' =>
  array(
    'ids' =>
    array(
      0 => 'Downtown',
    ),
    'q' => 1,
  ),
  'osc_query_item category and premium' =>
  array(
    'ids' =>
    array(
      0 => 'car2',
      1 => 'sport',
    ),
    'q' => 6,
  ),
  'osc_query_item region and user' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'tv',
    ),
    'q' => 6,
  ),
  'osc_query_item city area, page' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
    ),
    'q' => 5,
  ),
  'osc_query_item country, offset' =>
  array(
    'ids' =>
    array(
      0 => 'sport',
      1 => 'bike1',
      2 => 'sofa',
      3 => 'tv',
    ),
    'q' => 6,
  ),
  'osc_query_item id and pattern' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'q' => 5,
  ),
  'osc_query_item keyword string' =>
  array(
    'ids' =>
    array(
      0 => 'bike2',
      1 => 'car3',
    ),
    'q' => 6,
  ),
  'toJson empty' =>
  array(
    'json' => '{"price_min":0,"price_max":0,"aCategories":[],"city_areas":[],"cities":[],"regions":[],"countries":[],"withPattern":false,"sPattern":null,"tables":[],"tables_join":[],"no_catched_tables":[],"no_catched_conditions":[],"user_ids":null,"order_column":"dt_pub_date","order_direction":"DESC","limit_init":0,"results_per_page":10}',
    'q' => 0,
  ),
  'toJson filtered' =>
  array(
    'json' => '{"price_min":100,"price_max":9000,"aCategories":[1,"2"],"city_areas":["oc_t_item_location.fk_i_city_area_id = 2 "],"cities":["oc_t_item_location.s_city LIKE \'Aville\' "],"regions":["oc_t_item_location.fk_i_region_id = 1 ","oc_t_item_location.s_region LIKE \'Be\\\\\\"ta\' "],"countries":["oc_t_item_location.fk_c_country_code = \'us\' ","oc_t_item_location.s_country LIKE \'Canada\' "],"withPattern":true,"sPattern":"o\'neil \\"red car\\"","withPicture":true,"onlyPremium":true,"locale_code":["en_US","es_ES"],"tables":["x_table"],"tables_join":{"k":["x_join","x_join.id = 1","LEFT"]},"no_catched_tables":["x_table"],"no_catched_conditions":["1 = 1"],"user_ids":["oc_t_item.fk_i_user_id = 1 ","oc_t_item.fk_i_user_id = 2 "],"order_column":"i_price","order_direction":"ASC","limit_init":10,"results_per_page":5}',
    'q' => 2,
  ),
  'toJson user scalar' =>
  array(
    'json' => '{"price_min":0,"price_max":0,"aCategories":[],"city_areas":[],"cities":[],"regions":[],"countries":[],"withPattern":false,"sPattern":null,"tables":[],"tables_join":[],"no_catched_tables":[],"no_catched_conditions":[],"user_ids":3,"order_column":"dt_pub_date","order_direction":"DESC","limit_init":0,"results_per_page":10}',
    'q' => 0,
  ),
  'toJson username scalar' =>
  array(
    'json' => '{"price_min":0,"price_max":0,"aCategories":[],"city_areas":[],"cities":[],"regions":[],"countries":[],"withPattern":false,"sPattern":null,"tables":[],"tables_join":[],"no_catched_tables":[],"no_catched_conditions":[],"user_ids":"2","order_column":"dt_pub_date","order_direction":"DESC","limit_init":0,"results_per_page":10}',
    'q' => 2,
  ),
  'toJson after doSearch with pattern' =>
  array(
    'json' => '{"price_min":0,"price_max":0,"aCategories":[],"city_areas":[],"cities":[],"regions":["oc_t_item_location.s_region LIKE \'Beta\' "],"countries":[],"withPattern":true,"sPattern":"bike","locale_code":["en_US"],"tables":[],"tables_join":[],"no_catched_tables":[],"no_catched_conditions":[],"user_ids":null,"order_column":"dt_pub_date","order_direction":"DESC","limit_init":0,"results_per_page":10}',
    'q' => 5,
  ),
  'setJsonAlert round trip' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 5,
  ),
  'setJsonAlert onto a used Search' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
    ),
    'count' => 2,
    'q' => 6,
  ),
  'setJsonAlert bad envelope' =>
  array(
    'ids' =>
    array(
    ),
    'count' => 0,
    'q' => 2,
  ),
  'alert replay v2' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
      1 => 'car2',
    ),
    'count' => 2,
    'q' => 8,
  ),
  'alert replay v2 pattern and meta' =>
  array(
    'ids' =>
    array(
      0 => 'car1',
    ),
    'count' => 1,
    'q' => 8,
  ),
  'shared instance reused' =>
  array(
    'ids' =>
    array(
      0 => 'bike1',
      1 => 'bike2',
      2 => '|',
      3 => 'bike1',
    ),
    'count' => 1,
    'q' => 11,
  ),
);
