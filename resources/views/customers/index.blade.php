<!DOCTYPE html>
<html>
<head>
    <title>customer App</title>
    <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css">
</head>
<body>
<div class="container">

    <nav class="navbar navbar-inverse">
        <div class="navbar-header">
            <a class="navbar-brand" href="{{ URL::to('customers') }}">customer Alert</a>
        </div>
        <ul class="nav navbar-nav">
            <li><a href="{{ URL::to('customers') }}">View All customers</a></li>
            <li><a href="{{ URL::to('customers/create') }}">Create a customer</a>
        </ul>
    </nav>

    <h1>All the customers</h1>

    <!-- will be used to show any messages -->
    @if (Session::has('message'))
        <div class="alert alert-info">{{ Session::get('message') }}</div>
    @endif

    <table class="table table-striped table-bordered">
        <thead>
        <tr>
            <td>ID</td>
            <td>FQDN</td>
            <td>Actions</td>
        </tr>
        </thead>
        <tbody>
        @foreach($customers as $customer => $value)
            <tr>
                <td>{{ $value->id }}</td>
                <td>{{ $value->fqdn }}</td>

                <!-- we will also add show, edit, and delete buttons -->
                <td>

                    <!-- delete the customer (uses the destroy method DESTROY /customers/{id} -->
                    <!-- we will add this later since its a little more complicated than the other two buttons -->

                    <!-- show the customer (uses the show method found at GET /customers/{id} -->
                    <a class="btn btn-small btn-success" href="{{ URL::to('customers/' . $value->id) }}">Show this customer</a>

                    <!-- edit this customer (uses the edit method found at GET /customers/{id}/edit -->
                    <a class="btn btn-small btn-info" href="{{ URL::to('customers/' . $value->id . '/edit') }}">Edit this customer</a>

                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

</div>
</body>
</html>
