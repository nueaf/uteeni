Introduction

ActiveRecord is the base class that other models inherit from. It contains the basic CRUD functions and some basic validation of datatypes

Details
Find The find function takes 1 parameter, which should be the primary key of the object your are trying to find.

Example:

$object = New MyObject?();

$object->find(1);
find_by_property Finds a single object by the specified property

find_all_by_property Finds all objects by the specified property

Read/Find_all Fetches rows from the database. Takes the following parameters in order: where What to get like "id in(1,2,3,4,5)"; limit How many rows to get. limit_start First row to get order_by_field Which field to order by. order ASC og DESC

Create Creates a now row in the database, based on your object. If there are required fields, that are null, an exception will be thrown.

Update Updates an existing object. Uses the primary key to identify, if it exists. If there is now primary key it will use all set fields in the object to locate the row in the database. Be careful, that you don't update multiple rows, when updating objects without primary keys.

Destroy Destroys the object. Finds the rows the same way update does.

set Sets a property, if the datatype passes validation. Throws an exception if an unknown property is being set.

get Gets the datatype.

call Parses function calls that begin with either find_by or find_all_by to the find_by_property and find_all_by_property functions

find_primary Attempts to find the primary key of the class. Returns false if there is now primary key.

validate_data Validates the data against the meta data of the class
