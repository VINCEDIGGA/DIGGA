# DIGG-A prerequisites skills.


CI/CD :

https://www.youtube.com/watch?v=AknbizcLq4w

System design : 

https://www.youtube.com/watch?v=SE2KF-vxvS0

Docker : 

https://www.youtube.com/watch?v=3c-iBn73dDE

MCP : 

https://www.youtube.com/watch?v=oblaHqULUHk


# Quick Git \& GitHub Guide



\## 1. Essential Commands

| Command | Action | Watch Out For |

| `git status` | Shows modified/staged files   | Always run this first   |

| `git add <file>` | Stages files for commit   | Avoid `git add .` without a `.gitignore`   |

| `git commit -m "msg"` | Saves a local snapshot   | Keep messages clear and descriptive   |

| `git push origin <branch>` | Uploads local commits to GitHub   | Don't use `--force` on shared branches   |

| `git pull origin <branch>` | Downloads \& merges remote changes   | Can trigger surprise merge conflicts   |

| `git merge <branch>` | Combines another branch into yours   | Watch out for overlapping code changes   |



\## 2. The Industry Fix: Branches \& PRs

Never push directly to `main`   Instead:

1\. `git pull origin main` (Get latest)  

2\. `git checkout -b feat/my-work` (Create branch)  

3\. Work, `add`, and `commit`  

4\. `git push -u origin feat/my-work` (Push branch)  

5\. Open a \*\*Pull Request (PR)\*\* on GitHub for review and merging  



\### 2.1 Solo Routine (Working Alone)

```bash

git status

git add .

git commit -m "feat: short description"

git pull origin main

git push origin main

```



\## 3. Merge Conflicts

A conflict happens when two branches change the \*\*exact same line\*\* of code in different ways   Git pauses the merge and adds markers (`<<<<<<<`, `=======`, `>>>>>>>`) inside the file so you can fix it manually  



\### 3.1 How to Resolve

1\. \*\*Edit:\*\* Open the file, keep the correct code, and delete all Git markers (`<<<<<<<`, `=======`, `>>>>>>>`)  

2\. \*\*Stage:\*\* Run `git add <file>` to mark it resolved  

3\. \*\*Commit:\*\* Run `git commit` to finish the merge  



\*(Stuck? Run `git merge --abort` to cancel and start over  \*



