<?php
//DB CONNECTION
$conn = mysqli_connect("localhost", "root", "", "group_expense", 3307);
if (!$conn) die("DB Connection Failed: " . mysqli_connect_error());

// HELPER FUNCTIONS
function insertMember($conn, $name) {
    $stmt = mysqli_prepare($conn, "INSERT INTO members (member_name) VALUES (?)");
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function insertExpense($conn, $payer, $amount, $desc, $participants) {
    $stmt = mysqli_prepare($conn, "INSERT INTO expenses (expense_payer, amount, description) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sds", $payer, $amount, $desc);
    mysqli_stmt_execute($stmt);
    $expenseId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $stmt2 = mysqli_prepare($conn, "INSERT INTO expense_participants (expense_id, participant_name) VALUES (?, ?)");
    foreach ($participants as $p) {
        mysqli_stmt_bind_param($stmt2, "is", $expenseId, $p);
        mysqli_stmt_execute($stmt2);
    }
    mysqli_stmt_close($stmt2);
}

//FORM HANDLING
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!empty($_POST['member_name']) && isset($_POST['add_member'])) insertMember($conn, trim($_POST['member_name']));
    if (isset($_POST['add_expense'])) {
        $payer = $_POST['expense_payer'];
        $amount = floatval($_POST['expense_amount']);
        $desc = trim($_POST['expense_desc']);
        $participants = $_POST['participants'] ?? [];
        if ($payer && $amount > 0 && $desc && count($participants)) insertExpense($conn, $payer, $amount, $desc, $participants);
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

//FETCH MEMBERS
$members = [];
$result = mysqli_query($conn, "SELECT member_name FROM members");
while ($row = mysqli_fetch_assoc($result)) $members[] = $row['member_name'];

//FETCH EXPENSES
$expenses = [];
$result = mysqli_query($conn, "SELECT * FROM expenses");
while ($row = mysqli_fetch_assoc($result)) {
    $id = $row['expense_id'];
    $expenses[$id] = $row;
    $participants = [];
    $p = mysqli_query($conn, "SELECT participant_name FROM expense_participants WHERE expense_id = $id");
    while ($pr = mysqli_fetch_assoc($p)) $participants[] = $pr['participant_name'];
    $expenses[$id]['participants'] = $participants;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Group Expense Splitter</title>

<style>
/* FULL PAGE BACKGROUND IMAGE */
body { 
    font-family: Arial, sans-serif;
    margin:0; 
    padding:0;
    background: url('img7.jpeg') no-repeat center center fixed;
    background-size: cover;
}

h1 { 
    text-align:center; 
    background:#0078ff; 
    color:white; 
    padding:15px; 
    margin:0;
}

.container { 
    max-width:700px; 
    margin:20px auto; 
    background:white; 
    padding:20px; 
    border-radius:8px; 
    box-shadow:0 4px 12px rgba(0,0,0,0.1);
}

input, select, button { 
    margin:5px; 
    padding:8px; 
    border:1px solid #ccc; 
    border-radius:5px; 
    width:90%; 
    max-width:300px;
}

button { 
    background:#0078ff; 
    color:white; 
    border:none; 
    cursor:pointer;
}

button:hover { background:#005fcc; }

ul { list-style:none; padding-left:0; }

li { 
    background:#f2f2f2; 
    margin:5px 0; 
    padding:8px; 
    border-radius:5px; 
}

.participants-grid { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 10px;
}

.participant-item { 
    background: #f2f2f2; 
    padding: 6px 10px; 
    border-radius: 5px; 
    border:1px solid #ccc; 
    display:flex; 
    align-items:center; 
    cursor:pointer;
}

.participant-item input { margin-right:5px; }
</style>
</head>

<body>

<h1>Group Expense Splitter</h1>
<div class="container">

<h2>1️⃣ Add Members</h2>
<form method="POST">
    <input name="member_name" placeholder="Enter member name">
    <button type="submit" name="add_member">Add Member</button>
</form>
<ul><?php foreach($members as $m) echo "<li>$m</li>"; ?></ul>
<hr>

<h2>2️⃣ Add Expense</h2>
<form method="POST">
    <select name="expense_payer">
        <option value="">Select payer</option>
        <?php foreach($members as $m) echo "<option value='$m'>$m</option>"; ?>
    </select><br>
    <input name="expense_amount" type="number" placeholder="Amount"><br>
    <input name="expense_desc" type="text" placeholder="Description"><br>
    <h4>Select participants:</h4>
    <div class="participants-grid">
        <?php foreach($members as $m): ?>
            <label class="participant-item">
                <input type="checkbox" name="participants[]" value="<?= $m ?>"> <?= $m ?>
            </label>
        <?php endforeach; ?>
    </div>
    <button type="submit" name="add_expense">Add Expense</button>
</form>

<ul id="expenseList">
<?php foreach($expenses as $e) {
    echo "<li>{$e['expense_payer']} paid ₹{$e['amount']} for [" . implode(", ", $e['participants']) . "] ({$e['description']})</li>";
} ?>
</ul>

<hr>
<h2>3️⃣ Calculate Settlements</h2>
<button onclick="calculatePairwise()">Show Pairwise Settlements</button>
<div id="settlements"></div>

</div>

<script>
// JS: calculate settlements
function calculatePairwise() {
    var members = {};
    document.querySelectorAll(".participants-grid input").forEach(i => members[i.value]=0);

    var expenses = [];
    <?php foreach($expenses as $e): ?>
    expenses.push({payer:'<?= $e['expense_payer'] ?>', amount:<?= $e['amount'] ?>, participants:['<?= implode("','", $e['participants']) ?>']});
    <?php endforeach; ?>

    var ledger = {};
    Object.keys(members).forEach(a => {
        ledger[a] = {};
        Object.keys(members).forEach(b => ledger[a][b] = 0);
    });

    expenses.forEach(e => {
        var share = e.amount / e.participants.length;
        e.participants.forEach(p => { if(p!=e.payer) ledger[p][e.payer] += share; });
    });

    var html = "<h3>Net Pairwise Settlements</h3>";
    for(var a in ledger){
        var owes = "";
        for(var b in ledger[a]){
            if(a!=b){
                var net = ledger[a][b]-ledger[b][a];
                if(net>0.01) owes += "<li>Owes <b>"+b+"</b> ₹"+net.toFixed(2)+"</li>";
            }
        }
        if(owes) html+="<p><b>"+a+"</b>:</p><ul>"+owes+"</ul>";
    }
    document.getElementById("settlements").innerHTML = html;
}
</script>

</body>
</html>
