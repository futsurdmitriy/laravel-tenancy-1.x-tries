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

    <h1>Create a customer</h1>

    <!-- if there are creation errors, they will show here -->
    {{ HTML::ul($errors->all()) }}

    {{ Form::open(array('url' => 'customers')) }}

    <div class="form-group">
        {{ Form::label('fqdn', 'FQDN') }}
        {{ Form::text('fqdn', old('fqdn'), array('class' => 'form-control')) }}
    </div>

    {{ Form::submit('Create the customer!', array('class' => 'btn btn-primary')) }}

    {{ Form::close() }}

</div>
</body>
</html>
